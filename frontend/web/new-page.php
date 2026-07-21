<?php require __DIR__ . '/auth-guard.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Getway | System | New Page</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="style.php" />

  <!-- Tailwind CSS (CDN) -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Font Awesome (CDN) -->
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
  />
</head>
<body class="heslb-shell">
  <div class="navbar">
    <div class="navbar-inner">
      <button type="button" class="nav-menu-toggle" aria-label="Open navigation menu" aria-expanded="false" data-nav-toggle>☰</button>
      <div class="nav-title">Getway | System</div>
      <div class="nav-links">
        <a href="part-one.php">Part One</a>
        <a href="part-two.php">Part Two</a>
        <a class="active" href="new-page.php">New Page</a>
      </div>
    </div>
    <div class="nav-mobile-menu" data-nav-mobile-menu>
      <a href="part-one.php">Part One</a>
      <a href="part-two.php">Part Two</a>
      <a class="active" href="new-page.php">New Page</a>
    </div>
  </div>

  <div class="heslb-layout">
    <aside class="heslb-sidebar">
      <div class="heslb-brand">
        <div class="heslb-brand-logo">HESLB</div>
        <button class="heslb-burger" type="button" aria-label="Menu">≡</button>
      </div>

      <div class="heslb-menu-title">MENU</div>
      <nav class="heslb-menu">
        <a class="heslb-menu-item active" href="new-page.php">Home page</a>
        <a class="heslb-menu-item" href="#">Apply for loan</a>
        <a class="heslb-menu-item" href="#">Apply for Scholarship</a>
        <a class="heslb-menu-item" href="#">Click to Appeal</a>
        <a class="heslb-menu-item" href="#">Loan Repayment</a>
        <a class="heslb-menu-item" href="#">Login as Registered User</a>
        <a class="heslb-menu-item" href="#">Employer Login</a>
      </nav>
    </aside>

    <main class="heslb-main">
      <div class="heslb-top-title">
        HIGHER EDUCATION STUDENTS' LOANS BOARD
        <div class="heslb-top-subtitle">Online Loan Application and Management System</div>
      </div>

      <!-- Dashboard Section (Tailwind) -->
      <section class="max-w-5xl mx-auto px-2">
        <!-- Responsive grid: 1 column on mobile, 3 columns on desktop -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

          <!-- Card 1: Loan Applicants -->
          <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition transform hover:scale-[1.02] text-center">
            <!-- Centered icon (blue, large) -->
            <i class="fa-solid fa-user-plus text-[#007bff] text-5xl mb-4"></i>
            <!-- Title -->
            <h2 class="text-lg font-semibold text-gray-800">Loan Applicants</h2>

            <!-- Actions list (like screenshot) -->
            <div class="mt-5 space-y-3 text-left">
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Apply For Loan</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Apply For Scholarship</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Login for Registered Applicants</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Recover Forgotten Password</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            </div>
          </div>

          <!-- Card 2: Loan Beneficiaries -->
          <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition transform hover:scale-[1.02] text-center">
            <!-- Centered icon (blue, large) -->
            <i class="fa-solid fa-hand-holding-dollar text-[#007bff] text-5xl mb-4"></i>
            <!-- Title -->
            <h2 class="text-lg font-semibold text-gray-800">Loan Beneficiaries</h2>

            <!-- Actions list (like screenshot) -->
            <div class="mt-5 space-y-3 text-left">
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Create Account</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Login</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Make Payment</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Get Loan Statement</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Apply For Refund</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Recover Forgotten Password</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            </div>
          </div>

          <!-- Card 3: Employer's Portal -->
          <div class="bg-white p-6 rounded-xl shadow hover:shadow-lg transition transform hover:scale-[1.02] text-center">
            <!-- Centered icon (blue, large) -->
            <i class="fa-solid fa-building text-[#007bff] text-5xl mb-4"></i>
            <!-- Title -->
            <h2 class="text-lg font-semibold text-gray-800">Employer's Portal</h2>

            <!-- Actions list (like screenshot) -->
            <div class="mt-5 space-y-3 text-left">
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Login</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
              <a href="#" class="flex items-center justify-between bg-blue-50 hover:bg-blue-100 border border-blue-100 rounded-lg px-4 py-3 text-sm font-semibold text-blue-700 transition">
                <span>Recover Forgotten Password</span>
                <i class="fa-solid fa-arrow-right"></i>
              </a>
            </div>
          </div>

        </div>
      </section>
    </main>
  </div>
  <script src="script.js"></script>
</body>
</html>
