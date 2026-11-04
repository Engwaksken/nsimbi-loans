<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password - Nsimbi Impact</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/soft-ui-dashboard.css?v=1.0.3">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    .fade-out {
      animation: fadeOut 1s ease-out forwards;
    }
    @keyframes fadeOut {
      to { opacity: 0; visibility: hidden; }
    }
  </style>
</head>
<body>
  <?php session_start(); ?>
  <div class="container mt-5">
    <div class="row justify-content-center">
      <div class="col-lg-6 col-md-6 col-sm-8">
        <div class="card p-4 shadow">
          <h4 class="mb-4 text-center">Forgot Password</h4>

          <?php if (!empty($_SESSION['status'])): ?>
            <div id="statusMessage" class="alert alert-info text-center">
              <?= $_SESSION['status']; unset($_SESSION['status']); ?>
            </div>
            <script>
              setTimeout(() => {
                const message = document.getElementById('statusMessage');
                if (message) {
                  message.classList.add('fade-out');
                }
              }, 4000);

              setTimeout(() => {
                window.location.href = "index.php";
              }, 5000);
            </script>
          <?php endif; ?>

          <form action="php/send-reset.php" method="POST">
            <div class="mb-3">
              <label for="email" class="form-label">Enter your email address:</label>
              <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email address e.g. you@gmail.com" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
          </form>

          <a href="index.php" class="d-block mt-3 text-center text-info text-gradient text-decoration-none"><i class="fas fa-arrow-left me-2"></i>Back to login</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Optional: Bootstrap JS for components -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
