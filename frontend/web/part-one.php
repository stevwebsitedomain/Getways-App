<?php require __DIR__ . '/auth-guard.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Getway | System | Part One</title>
  <link rel="icon" type="image/png" href="images/favicon.png" />
  <link rel="stylesheet" href="style.php" />
</head>
<body>
  <div class="navbar">
    <div class="navbar-inner">
      <button type="button" class="nav-menu-toggle" aria-label="Open navigation menu" aria-expanded="false" data-nav-toggle>☰</button>
      <div class="nav-title">Getway | System</div>
      <div class="nav-links">
        <a class="active" href="part-one.php">Part One</a>
        <a href="part-two.php">Part Two</a>
        <a href="create-payment.php">Checkout</a>
        <a href="new-page.php">New Page</a>
      </div>
    </div>
    <div class="nav-mobile-menu" data-nav-mobile-menu>
      <a class="active" href="part-one.php">Part One</a>
      <a href="part-two.php">Part Two</a>
      <a href="new-page.php">New Page</a>
    </div>
  </div>

  <div class="container">
    <h2>Search Student Results</h2>

    <div class="form">
      <input type="text" id="index" placeholder="Enter Index Number" />

      <select id="year">
        <option value="">Select Year</option>
        <option value="2020">2020</option>
        <option value="2021">2021</option>
        <option value="2022">2022</option>
        <option value="2023">2023</option>
        <option value="2024">2024</option>
        <option value="2025">2025</option>
      </select>

      <select id="level">
        <option value="">Select Level</option>
        <option value="CSEE">Form Four (CSEE)</option>
        <option value="ACSEE">Form Six (ACSEE)</option>
      </select>

      <button onclick="searchResult()">Search</button>
    </div>

    <div id="loading" class="loading" style="display:none;">Searching...</div>
    <div id="result" class="result"></div>
  </div>

  <script src="script.js"></script>
</body>
</html>
