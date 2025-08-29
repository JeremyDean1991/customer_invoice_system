<?php include 'db.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Upload Excel - Customer System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>

<body class="bg-light">
  <div class="container mt-5">
    <div class="card shadow p-4">
      <h2 class="mb-4">Upload Customer Excel</h2>
      <form action="upload.php" method="POST" enctype="multipart/form-data">
        <div class="mb-3">
          <label class="form-label">Choose Excel File (.xlsx)</label>
          <input type="file" name="excel_file" class="form-control" required>
        </div>
        <button type="submit" name="upload" class="btn btn-primary">Upload</button>
        <a href="records.php" class="btn btn-outline-primary mx-3">
          Records
        </a>
      </form>
    </div>
  </div>
  <?php
  if (isset($_POST['upload'])) {
    $targetDir = "files/";
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0777, true);
    }
    $fileName = time() . "_" . basename($_FILES["excel_file"]["name"]);
    $targetFile = $targetDir . $fileName;
    if (move_uploaded_file($_FILES["excel_file"]["tmp_name"], $targetFile)) {
      echo "<div class='container mt-4 alert alert-success'>Excel uploaded successfully: $fileName</div>";
      // 🚫 Removed DB insert here
      // ✅ Instead, redirect straight to generate_pdf.php
      echo "<div class='container mt-3'>
                <a href='generate_pdf.php?file=$fileName' class='btn btn-success'>Generate PDFs</a>
              </div>";
    } else {
      echo "<div class='container mt-4 alert alert-danger'>Error uploading file.</div>";
    }
  }
  ?>
</body>

</html>