<?php
/**
 * Shared notification helper — inserts the DB notification (the
 * always-authoritative record every page's bell dropdown already reads)
 * and best-effort sends the same message as an email.
 *
 * 2026-09-14 — built to replace the ~18 hand-copied "INSERT INTO
 * notifications" blocks scattered across the demand pipeline, and to fix a
 * real, silent bug found while doing this: admin_page.php's two existing
 * PHPMailer call sites gated sending behind `class_exists('PHPMailer')`,
 * but PHPMailer 6.x is namespaced (PHPMailer\PHPMailer\PHPMailer) — that
 * check always returned false, so those two "working" email features had
 * silently never actually sent an email, ever, despite valid SMTP
 * credentials. Confirmed directly: class_exists('PHPMailer') === false,
 * class_exists('PHPMailer\PHPMailer\PHPMailer') === true.
 *
 * 2026-09-14, same evening — second real bug found via the user's own
 * manual testing: the first version of this file opened a brand-new SMTP
 * connection (full TCP + TLS handshake + Gmail auth) for every single
 * notifyUser() call. For a single-recipient action that's just a few
 * seconds of felt delay ("did my click even work?"), but admin_page.php's
 * deadline blast loops over EVERY accepted user - each paying that same
 * handshake cost separately - and blew straight through PHP's 120-second
 * max_execution_time, fatal-erroring the whole request. Confirmed live:
 * the deadline was actually still created and some emails did go out
 * before the timeout killed the page, which is arguably worse than a
 * clean failure (partial, silent under-delivery with no way to tell who
 * was missed). Fixed by keeping ONE SMTP connection open (SMTPKeepAlive)
 * and reusing it across every notifyUser() call in the same request,
 * closing it automatically when the request ends - the same
 * connect-once-send-many shape the original (never-actually-running)
 * deadline code already intended via its own clearAddresses()/addAddress()
 * loop, just now actually in effect and applied everywhere, not only there.
 *
 * Email is strictly a bonus on top of the DB notification, matching the
 * pattern admin_page.php already established: a missing library, a bad
 * SMTP credential, or a network hiccup must never block or fail the
 * underlying action (approving a user, confirming a participant, etc.) —
 * only the DB write determines this function's return value, and every
 * mail failure is caught and logged, never thrown.
 *
 * Usage: require_once this file (after config.php, so $con and the
 * $smtp_* globals from db_credentials.php already exist), then call
 * notifyUser() wherever an "INSERT INTO notifications" used to live.
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Safety net alongside the connection-reuse fix below: even with one kept-
// alive connection, a slow network or an unusually large recipient list
// (e.g. if the accepted-user count grows well past today's ~15) shouldn't
// be able to fatal-error the whole request the way the 120s default just
// did. This only raises the ceiling for scripts that load this file - it
// does not change php.ini globally. false = no limit; kept at 10 minutes
// instead of unlimited so a genuinely stuck connection still gives up
// eventually rather than hanging a request forever.
set_time_limit(600);

/**
 * @param mysqli      $con
 * @param int         $userId       Recipient's users.id.
 * @param string      $message      Plain-text body — used verbatim for the DB row and (HTML-escaped) as the email body.
 * @param int|null    $relatedId    Same meaning as every existing notifications.related_id usage.
 * @param string      $relatedType  Same meaning as every existing notifications.related_type usage.
 * @param string|null $emailSubject Defaults to a generic TrainWise subject if omitted.
 * @return bool true if the DB notification was recorded. Email success/failure never affects this.
 */
function notifyUser($con, $userId, $message, $relatedId, $relatedType, $emailSubject = null) {
    $dbOk = false;

    $stmt = $con->prepare("INSERT INTO notifications (user_id, message, related_id, related_type, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
    if ($stmt) {
        $stmt->bind_param("isis", $userId, $message, $relatedId, $relatedType);
        $dbOk = $stmt->execute();
        $stmt->close();
    }

    if ($dbOk) {
        $updateStmt = $con->prepare("UPDATE users SET has_notification = TRUE WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("i", $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    sendNotificationEmail($con, $userId, $message, $emailSubject);

    return $dbOk;
}

/**
 * Returns a single PHPMailer instance shared for the lifetime of the
 * current request, connected once with SMTPKeepAlive so every subsequent
 * call in the same loop (a deadline fan-out, several requesters on one
 * demand, several deans on one forward) reuses the open connection instead
 * of renegotiating TLS + auth from scratch. Reconnects automatically if a
 * previous send left the connection closed (PHPMailer's own $mail->smtp
 * exposes Connected() for exactly this check).
 */
function getSharedMailer() {
    static $mail = null;

    if ($mail === null) {
        global $smtp_host, $smtp_user, $smtp_pass, $smtp_from_name, $smtp_port, $smtp_secure;

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host         = $smtp_host;
        $mail->SMTPAuth     = true;
        $mail->Username     = $smtp_user;
        $mail->Password     = $smtp_pass;
        $mail->SMTPSecure   = $smtp_secure;
        $mail->Port         = $smtp_port;
        $mail->Timeout      = 15;
        $mail->SMTPKeepAlive = true; // the actual fix - see file-level comment
        $mail->setFrom($smtp_user, $smtp_from_name);
        $mail->isHTML(true);

        // Close the persistent connection cleanly when the request ends,
        // rather than leaving it to time out on Gmail's side.
        register_shutdown_function(function () use ($mail) {
            if ($mail->getSMTPInstance()->connected()) {
                $mail->smtpClose();
            }
        });
    }

    return $mail;
}

/**
 * Sends the best-effort email half on its own — split out so a caller that
 * already knows it's notifying several people in a loop (the common shape
 * across the demand-pipeline files) can call notifyUser() per recipient
 * without re-deriving this logic, and so a caller with an unusual
 * notifications-table shape (e.g. an INSERT...SELECT fan-out to every user
 * of a role) can still get the email half by calling this directly per
 * resolved user_id after doing its own DB insert.
 */
function sendNotificationEmail($con, $userId, $message, $emailSubject = null) {
    // Master switch, defined in config.php. Off (or undefined) = no SMTP
    // connection is ever opened, so notifyUser() is just its DB writes.
    if (!defined('EMAIL_NOTIFICATIONS_ENABLED') || !EMAIL_NOTIFICATIONS_ENABLED) {
        return false;
    }

    // The real, namespaced class name — see the file-level comment above
    // for why a bare class_exists('PHPMailer') check is a silent no-op bug.
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return false;
    }

    global $smtp_user, $smtp_pass;
    if (empty($smtp_user) || empty($smtp_pass)) {
        return false;
    }

    try {
        $userStmt = $con->prepare("SELECT email, name FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $recipient = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if (!$recipient || empty($recipient['email'])) {
            return false;
        }

        $mail = getSharedMailer();
        $mail->clearAddresses();
        $mail->addAddress($recipient['email'], $recipient['name']);
        $mail->Subject = $emailSubject ?: 'TrainWise Notification';
        $mail->Body    = nl2br(htmlspecialchars($message)) . '<br><br><span style="color:#888;font-size:12px;">This is an automated message from TrainWise. Please do not reply to this email.</span>';
        $mail->AltBody = $message;

        return $mail->send();
    } catch (\Throwable $e) {
        error_log("sendNotificationEmail: failed for user_id={$userId}: " . $e->getMessage());
        return false;
    }
}
