<?php
require_once 'init.php';
require_login();
require_role(['official']);

$msg = '';
$error = '';

// Add/Edit inventory
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = $_POST['action'];
  $section = trim($_POST['section'] ?? 'Other');
  if ($section === '') $section = 'Other';
  $item_description = $_POST['item_description'] ?? '';
  $status = $_POST['status'] ?? '';
  $required_quantity = (int)($_POST['required_quantity'] ?? 0);
  $current_stock = (int)($_POST['current_stock'] ?? 0);
  $notes = $_POST['notes'] ?? '';

  if ($action === 'add') {
    $stmt = $conn->prepare('INSERT INTO inventory (item_description, section, status, required_quantity, current_stock, notes) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssiis', $item_description, $section, $status, $required_quantity, $current_stock, $notes);
    if ($stmt->execute()) {
      $msg = 'Item added successfully!';
      audit_log('INVENTORY_ITEM_ADDED', $item_description, $section . '; stock ' . $current_stock);
    } else {
      $error = 'Error adding item: ' . $conn->error;
    }
  } elseif ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare('UPDATE inventory SET item_description=?, section=?, status=?, required_quantity=?, current_stock=?, notes=? WHERE id=?');
    $stmt->bind_param('sssiisi', $item_description, $section, $status, $required_quantity, $current_stock, $notes, $id);
    if ($stmt->execute()) {
      $msg = 'Item updated successfully!';
      audit_log('INVENTORY_ITEM_UPDATED', $item_description, 'item #' . $id . '; stock ' . $current_stock);
    } else {
      $error = 'Error updating item: ' . $conn->error;
    }
  }
}

// Delete inventory
if (isset($_GET['delete'])) {
  $id = (int)$_GET['delete'];
  if ($conn->query("DELETE FROM inventory WHERE id=$id")) {
    $msg = 'Item deleted successfully!';
    audit_log('INVENTORY_ITEM_DELETED', 'item #' . $id);
  }
}

$res = $conn->query('SELECT * FROM inventory ORDER BY id DESC');
$inventory = [];
while($row = $res->fetch_assoc()) $inventory[] = $row;

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Inventory</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="assets/style.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999; align-items:center; justify-content:center; }
    .modal.active { display:flex; }
    .modal .card { max-width:500px; width:90%; max-height:90vh; overflow-y:auto; }
    .modal-close { float:right; cursor:pointer; font-size:20px; color:#999; }
  </style>
</head>
<body>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>

  <div style="flex:1;display:flex;flex-direction:column;">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;display:flex;justify-content:space-between;align-items:center;">
      <h2 style="margin:0;">Manage Inventory</h2>
      <button class="btn" onclick="document.getElementById('addForm').classList.add('active');">➕ Add New Item</button>
    </header>

    <main class="main-content" style="flex:1;overflow-y:auto;">
      <?php if($msg): ?><div class="notice" style="margin:20px;"><?php echo e($msg); ?></div><?php endif; ?>
      <?php if($error): ?><div class="errors" style="margin:20px;"><?php echo e($error); ?></div><?php endif; ?>

      <div class="container">
        <section class="card">
          <h3 style="background:var(--primary);color:#fff;padding:12px;margin:-20px -20px 0 -20px;">Current Inventory Items</h3>
          <div class="table-wrapper" style="margin-top:0;">
            <table class="table">
              <thead>
                <tr>
                  <th>ITEM</th>
                  <th>SECTION</th>
                  <th>STATUS</th>
                  <th>REQUIRED</th>
                  <th>CURRENT</th>
                  <th>ACTION</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($inventory)): ?>
                  <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;">No items yet</td></tr>
                <?php else: ?>
                  <?php foreach($inventory as $item): ?>
                    <tr>
                      <td><?php echo e($item['item_description']); ?></td>
                      <td><?php echo e($item['section'] ?? 'Other'); ?></td>
                      <td>
                        <?php 
                          $status = $item['status'];
                          if($status === 'Verified') echo '<span class="badge badge-verified">Verified</span>';
                          elseif($status === 'Low Stock') echo '<span class="badge badge-low-stock">Low Stock</span>';
                          elseif($status === 'Pending Repair') echo '<span class="badge badge-pending">Pending Repair</span>';
                          else echo '<span style="color:#999;">—</span>';
                        ?>
                      </td>
                      <td><?php echo (int)$item['required_quantity']; ?></td>
                      <td><?php echo (int)$item['current_stock']; ?></td>
                      <td>
                        <button class="btn btn-small" onclick="editItem(<?php echo (int)$item['id']; ?>)">✏️ Edit</button>
                        <a href="?delete=<?php echo (int)$item['id']; ?>" class="btn btn-small" onclick="return confirm('Delete?')">🗑️ Delete</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  </div>
</div>

<!-- Add Item Modal -->
<div id="addForm" class="modal">
  <div class="card">
    <span class="modal-close" onclick="document.getElementById('addForm').classList.remove('active')">&times;</span>
    <h3>Add New Inventory Item</h3>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <label>Section</label>
      <select name="section">
        
        <option>Tools &amp; Equipment</option>
        <option>Medical Supplies</option>
        <option>Other</option>
      </select>
      <label>Item Description</label>
      <input name="item_description" required>
      <label>Status</label>
      <select name="status">
        <option value="">Select Status</option>
        <option value="Verified">✓ Verified</option>
        <option value="Low Stock">⚠ Low Stock</option>
        <option value="Pending Repair">◐ Pending Repair</option>
      </select>
      <label>Required Quantity</label>
      <input name="required_quantity" type="number" value="0">
      <label>Current Stock</label>
      <input name="current_stock" type="number" value="0">
      <label>Notes</label>
      <textarea name="notes" rows="3"></textarea>
      <button type="submit" class="btn" style="width:100%;">Add Item</button>
    </form>
  </div>
</div>

<script>
function editItem(id) {
  alert('Edit the item through the add form or update it from the inventory list.');
}
</script>
</body>
</html>
