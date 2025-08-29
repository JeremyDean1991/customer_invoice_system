<?php
include 'db.php';

// --- Handle delete request here ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
  $id = intval($_POST['delete_id']);

  // Fetch record to also delete files
  $res = $conn->prepare("SELECT excel_file, invoice_pdf, pbe_pdf FROM records WHERE id=?");
  $res->bind_param("i", $id);
  $res->execute();
  $res->bind_result($excel, $invoice, $pbe);
  $res->fetch();
  $res->close();

  // Delete files if exist
  foreach ([$excel, $invoice, $pbe] as $f) {
    if ($f && file_exists(__DIR__ . "/$f")) {
      unlink(__DIR__ . "/$f");
    }
  }

  // Delete record from DB
  $stmt = $conn->prepare("DELETE FROM records WHERE id=?");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $stmt->close();

  $msg = "Record deleted successfully.";
}

// Delete All
if (isset($_POST['delete_all'])) {
  $conn->query("TRUNCATE TABLE records"); // deletes all rows + resets auto-increment
  header("Location: records.php?msg=All+records+deleted");
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Records - Customer System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body class="bg-light">
  <div class="container mt-5">

    <div class="d-flex justify-content-between align-items-center mb-4">
      <h2 class="mb-0">Approved Records</h2>
      <div class="d-flex justify-content-between align-items-center mb-4">
        <form method="POST" action="records.php" onsubmit="return confirm('⚠️ Are you sure you want to delete ALL records?');">
          <button type="submit" name="delete_all" class="btn btn-danger">Delete All</button>
        </form>

        <a href="upload.php" class="btn btn-outline-primary mx-3">
          ← Back to Upload
        </a>
      </div>

    </div>

    <?php if (!empty($msg)): ?>
      <!-- Toast -->
      <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
        <div id="liveToast" class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true">
          <div class="d-flex">
            <div class="toast-body">
              <?= $msg ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
          </div>
        </div>
      </div>
      <script>
        // Auto-hide toast after 3 seconds
        setTimeout(() => {
          const toastEl = document.getElementById('liveToast');
          if (toastEl) {
            const toast = bootstrap.Toast.getOrCreateInstance(toastEl);
            toast.hide();
          }
        }, 3000);
      </script>
    <?php endif; ?>


    <table class="table table-bordered">
      <tr>
        <th>No</th>
        <th>FileName</th>
        <th>Excel</th>
        <th>Invoice</th>
        <th>PBE</th>
        <th>Status</th>
        <th>Date</th>
        <th>Actions</th>
      </tr>
      <?php
      $result = $conn->query("SELECT * FROM records ORDER BY id DESC");
      $num = 1;
      while ($row = $result->fetch_assoc()): ?>
        <tr>
          <td><?= $num++ ?></td>
          <td><?= $row['file_name'] ?></td>
          <td><a href="files/<?= $row['excel_file'] ?>" target="_blank" class="btn btn-sm btn-success">Excel</a></td>
          <td>
            <?php if ($row['invoice_pdf']) : ?>
              <a href="generate_pdf.php?download=<?= basename($row['invoice_pdf']) ?>" class="btn btn-sm btn-primary">
                Download Invoice
              </a>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($row['pbe_pdf']) : ?>
              <a href="generate_pdf.php?download=<?= basename($row['pbe_pdf']) ?>" class="btn btn-sm btn-secondary">
                Download PBE
              </a>
            <?php endif; ?>
          </td>
          <td><?= $row['status'] ?></td>
          <td><?= $row['upload_date'] ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('Delete this record?');">
              <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
  </div>
</body>

</html>