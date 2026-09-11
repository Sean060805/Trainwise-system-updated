<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Submission</title>
  <link rel="stylesheet" href="assets/css/tw-51.css">
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.6.0/remixicon.min.css" />
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f3f4f6;
    }
    .chart-container {
      position: relative;
      height: 320px;
      width: 100%;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.5rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    .badge-on-time {
      background-color: #d1fae5;
      color: #065f46;
    }
    .badge-late {
      background-color: #fef3c7;
      color: #92400e;
    }
    .badge-no-submission {
      background-color: #fee2e2;
      color: #991b1b;
    }
    .shadow-custom {
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    .shadow-custom-hover:hover {
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    .deadline-preview {
      transition: all 0.3s ease;
    }
    .notification-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      background-color: #ef4444;
      color: white;
      border-radius: 9999px;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      font-weight: 500;
    }
    .notification-dropdown {
      max-height: 300px;
      overflow-y: auto;
      width: 320px;
      right: 0;
      z-index: 50;
    }
    .notification-item {
      transition: background-color 0.2s;
    }
    .notification-item:hover {
      background-color: #f9fafb;
    }
  </style>
</head>

<body>
<div class="flex h-screen">
<!-- Sidebar -->
<aside class="w-64 bg-blue-900 text-white shadow-sm">
  <div class="h-full flex flex-col">
    <!-- Logo and Title -->
    <div class="p-6 flex items-center">
      <img src="images/lspu-logo.png" alt="Logo" class="w-10 h-10 mr-2" />
      <a href="admin_page.php" class="text-lg font-semibold text-white">Admin Dashboard</a>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-4">
      <div class="space-y-1">
        <!-- Dashboard -->
        <a href="admin_page.php"
           class="flex items-center px-4 py-2.5 text-sm font-medium rounded-md 
                  <?= basename($_SERVER['PHP_SELF']) === 'admin_page.php' ? 'bg-blue-800' : '' ?> 
                  hover:bg-blue-700 transition-all">
          <i class="ri-dashboard-line mr-3"></i>
          Dashboard
        </a>

        <!-- Assessment Forms Section (Closed by default) -->
        <div x-data="{ open: false }">
          <button @click="open = !open"
                  class="w-full flex items-center justify-between px-4 py-2.5 text-sm font-medium rounded-md 
                         hover:bg-blue-700 transition-all">
            <div class="flex items-center">
              <i class="ri-file-list-3-line mr-3"></i>
              <span>Assessment Forms</span>
            </div>
            <i class="ri-arrow-down-s-line" x-show="open"></i>
            <i class="ri-arrow-right-s-line" x-show="!open"></i>
          </button>

          <div x-show="open" x-cloak class="pl-8 mt-1 space-y-1">
            <a href="submissions.php"
               class="flex items-center px-4 py-2 text-sm rounded-md 
                      <?= basename($_SERVER['PHP_SELF']) === 'submissions.php' ? 'bg-blue-800' : '' ?> 
                      hover:bg-blue-700 transition-all">
              <i class="ri-list-check mr-3"></i>
              Submissions
            </a>
            <a href="validation.php"
               class="flex items-center px-4 py-2 text-sm rounded-md 
                      <?= basename($_SERVER['PHP_SELF']) === 'validation.php' ? 'bg-blue-800' : '' ?> 
                      hover:bg-blue-700 transition-all">
              <i class="ri-shield-check-line mr-3"></i>
              HR Validation
            </a>
            <a href="reports.php"
               class="flex items-center px-4 py-2 text-sm rounded-md 
                      <?= basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'bg-blue-800' : '' ?> 
                      hover:bg-blue-700 transition-all">
              <i class="ri-bar-chart-line mr-3"></i>
              Reports
            </a>
          </div>
        </div>

        <!-- IDP Forms Section (Closed by default) -->
        <div x-data="{ open: false }">
          <button @click="open = !open"
                  class="w-full flex items-center justify-between px-4 py-2.5 text-sm font-medium rounded-md 
                         hover:bg-blue-700 transition-all">
            <div class="flex items-center">
              <i class="ri-file-list-3-line mr-3"></i>
              <span>IDP Forms</span>
            </div>
            <i class="ri-arrow-down-s-line" x-show="open"></i>
            <i class="ri-arrow-right-s-line" x-show="!open"></i>
          </button>

          <div x-show="open" x-cloak class="pl-8 mt-1 space-y-1">
            <a href="idp_forms.php"
               class="flex items-center px-4 py-2 text-sm rounded-md 
                      <?= basename($_SERVER['PHP_SELF']) === 'idp_forms.php' ? 'bg-blue-800' : '' ?> 
                      hover:bg-blue-700 transition-all">
              <i class="ri-list-check mr-3"></i>
              View IDPs
            </a>
            <a href="idp_reports.php"
               class="flex items-center px-4 py-2 text-sm rounded-md 
                      <?= basename($_SERVER['PHP_SELF']) === 'idp_reports.php' ? 'bg-blue-800' : '' ?> 
                      hover:bg-blue-700 transition-all">
              <i class="ri-bar-chart-line mr-3"></i>
              IDP Reports
            </a>
          </div>
        </div>

        <!-- Assessment Form -->
        <a href="Assessment_Form.php"
           class="flex items-center px-4 py-2.5 text-sm font-medium rounded-md 
                  <?= basename($_SERVER['PHP_SELF']) === 'Assessment_Form.php' ? 'bg-blue-800' : '' ?> 
                  hover:bg-blue-700 transition-all">
          <i class="ri-file-list-2-line mr-3"></i>
          Assessment Form
        </a>

        <!-- Settings -->
        <a href="settings.php"
           class="flex items-center px-4 py-2.5 text-sm font-medium rounded-md 
                  <?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'bg-blue-800' : '' ?> 
                  hover:bg-blue-700 transition-all">
          <i class="ri-settings-3-line mr-3"></i>
          Settings
        </a>
      </div>
    </nav>

    <!-- Sign Out -->
    <div class="p-4">
      <a href="index.php"
         class="flex items-center px-4 py-2.5 text-sm font-medium rounded-md hover:bg-red-500 text-white transition-all">
        <i class="ri-logout-box-line mr-3"></i>
        Sign Out
      </a>
    </div>
  </div>
</aside>
<!-- Include AlpineJS -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

<style>
  /* Hide dropdown content before Alpine loads */
  [x-cloak] { display: none !important; }
</style>