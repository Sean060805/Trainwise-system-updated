<?php
/**
 * Template for db_credentials.php - copy this file to db_credentials.php
 * and fill in real values. db_credentials.php itself is gitignored so
 * real secrets never get committed.
 */

// Local (XAMPP) database
$local_host     = "localhost";
$local_user     = "root";
$local_password = "";
$local_database = "your_local_db_name";

// Production database
$prod_host     = "";
$prod_user     = "";
$prod_password = "";
$prod_database = "";

// SMTP (used by admin_page.php for approval/notification emails)
$smtp_host      = "smtp.gmail.com";
$smtp_user      = "";
$smtp_pass      = "";
$smtp_from_name = "LSPU Admin";
$smtp_port      = 587;
$smtp_secure    = "tls";
