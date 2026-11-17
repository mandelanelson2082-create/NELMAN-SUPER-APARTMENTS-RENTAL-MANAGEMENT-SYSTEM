<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '') ?: null;
        $id = (int) ($_POST['id'] ?? 0);
        if ($name === '') {
            flash_set('error', 'Property name is required.');
        } elseif ($id > 0) {
            db_exec('UPDATE properties SET name=?, location=? WHERE id=?', [$name, $location, $id], 'ssi');
            flash_set('success', 'Property updated.');
        } else {
            db_insert('INSERT INTO properties (name, location) VALUES (?,?)', [$name, $location], 'ss');
            flash_set('success', 'Property registered.');
        }
    } elseif ($op === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $units = (int) db_value('SELECT COUNT(*) FROM houses WHERE property_id=?', [$id], 'i');
        if ($units > 0) {
            flash_set('error', 'Cannot delete a property that still has house units.');
        } else {
            db_exec('DELETE FROM properties WHERE id=?', [$id], 'i');
            flash_set('success', 'Property deleted.');
        }
    }
    redirect(base_url('admin/properties.php'));
}

$edit = null;
if (($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    $edit = get_property((int) $_GET['id']);
}

$properties = db_all(
    'SELECT p.*,
            (SELECT COUNT(*) FROM houses h WHERE h.property_id=p.id) units,
            (SELECT COUNT(*) FROM houses h WHERE h.property_id=p.id AND h.status="OCCUPIED") occupied
       FROM properties p ORDER BY p.name'
);

$pageTitle = 'Properties';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-buildings"></i> Property / Building Directory</h1>
  <?php if (($_GET['action'] ?? '') !== 'edit'): ?>
    <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#propModal"><i class="bi bi-plus-lg"></i> Register Building</button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php foreach ($properties as $p): $occ = $p['units'] ? round($p['occupied']/$p['units']*100) : 0; ?>
    <div class="col-md-4">
      <div class="card h-100">
        <div class="card-body">
          <h2 class="h6 fw-bold mb-1"><i class="bi bi-building text-primary"></i> <?= e($p['name']) ?></h2>
          <p class="text-muted small mb-2"><i class="bi bi-geo-alt"></i> <?= e($p['location'] ?? '—') ?></p>
          <div class="d-flex justify-content-between small mb-1"><span><?= (int)$p['occupied'] ?>/<?= (int)$p['units'] ?> occupied</span><strong><?= $occ ?>%</strong></div>
          <div class="progress mb-3" style="height:6px"><div class="progress-bar bg-primary" style="width:<?= $occ ?>%"></div></div>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/houses.php?property_id='.$p['id'])) ?>"><i class="bi bi-house-door"></i> Units</a>
            <a class="btn btn-sm btn-outline-dark" href="<?= e(base_url('admin/properties.php?action=edit&id='.$p['id'])) ?>"><i class="bi bi-pencil"></i></a>
            <?php if ((int)$p['units'] === 0): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete property <?= e($p['name']) ?>?')">
                <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$properties): ?>
    <div class="col-12"><div class="alert alert-secondary">No properties yet. Register a building to get started.</div></div>
  <?php endif; ?>
</div>

<!-- Add/Edit modal -->
<div class="modal fade" id="propModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="modal-header"><h5 class="modal-title"><?= $edit?'Edit':'Register' ?> Building</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Property Name</label>
          <input class="form-control" name="name" required value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Sunrise Apartments"></div>
        <div class="mb-3"><label class="form-label">Location / Address</label>
          <input class="form-control" name="location" value="<?= e($edit['location'] ?? '') ?>" placeholder="e.g. Westlands, Nairobi"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-dark">Save Property</button></div>
    </form>
  </div></div>
</div>
<?php if ($edit): ?>
<script>new bootstrap.Modal(document.getElementById('propModal')).show();</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
