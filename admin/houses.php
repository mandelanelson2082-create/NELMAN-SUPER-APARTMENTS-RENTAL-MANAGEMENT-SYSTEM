<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

$action = $_GET['action'] ?? 'list';

// ---- handle add/edit/delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        $id = trim($_POST['id'] ?? '');
        $rent = (float) ($_POST['rent_amount'] ?? 0);
        $propRaw = $_POST['property_id'] ?? '';
        $propId = ($propRaw === '' ? null : (int) $propRaw);
        $existing = $_POST['existing_id'] ?? '';
        if ($id === '' || $rent < 0) {
            flash_set('error', 'House ID and a valid rent amount are required.');
        } elseif ($existing !== '') {
            // edit: if id changed, ensure new id not taken
            if ($id !== $existing && get_house($id)) {
                flash_set('error', 'Another house already uses ID ' . $id);
            } else {
                if ($id !== $existing) {
                    db_exec('UPDATE houses SET id=?, rent_amount=?, property_id=? WHERE id=?',
                        [$id, $rent, $propId, $existing], 'sdis');
                } else {
                    db_exec('UPDATE houses SET rent_amount=?, property_id=? WHERE id=?',
                        [$rent, $propId, $id], 'dis');
                }
                flash_set('success', 'House updated.');
            }
        } else {
            if (get_house($id)) {
                flash_set('error', 'House ID ' . $id . ' already exists.');
            } else {
                db_exec('INSERT INTO houses (id, property_id, rent_amount, status) VALUES (?,?,?,?)',
                    [$id, $propId, $rent, 'VACANT'], 'sids');
                flash_set('success', 'House ' . $id . ' added.');
            }
        }
    } elseif ($op === 'delete') {
        $id = $_POST['id'] ?? '';
        $occupiedBy = db_value('SELECT COUNT(*) FROM tenants WHERE house_id=? AND status="ACTIVE"', [$id], 's');
        if ($occupiedBy) {
            flash_set('error', 'Cannot delete an occupied house.');
        } else {
            db_exec('DELETE FROM houses WHERE id=?', [$id], 's');
            flash_set('success', 'House deleted.');
        }
    }
    redirect(base_url('admin/houses.php'));
}

$editHouse = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $editHouse = get_house((string) $_GET['id']);
}

$properties = all_properties();
$filterProp = isset($_GET['property_id']) && $_GET['property_id'] !== '' ? (int) $_GET['property_id'] : null;

if ($filterProp !== null) {
    $houses = db_all(
        'SELECT h.*, p.name property_name,
                (SELECT name FROM tenants t WHERE t.house_id=h.id AND t.status="ACTIVE" LIMIT 1) tenant_name
           FROM houses h LEFT JOIN properties p ON p.id = h.property_id
          WHERE h.property_id = ? ORDER BY h.id',
        [$filterProp], 'i'
    );
} else {
    $houses = db_all(
        'SELECT h.*, p.name property_name,
                (SELECT name FROM tenants t WHERE t.house_id=h.id AND t.status="ACTIVE" LIMIT 1) tenant_name
           FROM houses h LEFT JOIN properties p ON p.id = h.property_id ORDER BY h.id'
    );
}
$activeFilter = $filterProp !== null ? get_property($filterProp) : null;

$pageTitle = 'Houses';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-house-door"></i> House Management</h1>
  <div>
    <?php if ($activeFilter): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/houses.php')) ?>"><i class="bi bi-x-lg"></i> Clear filter</a>
    <?php endif; ?>
    <?php if ($action !== 'list'): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_url('admin/houses.php')) ?>"><i class="bi bi-x-lg"></i> Cancel</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($activeFilter): ?>
  <div class="alert alert-secondary small py-2">
    <i class="bi bi-funnel"></i> Showing units in <strong><?= e($activeFilter['name']) ?></strong>
    <?php if (!empty($activeFilter['location'])): ?> &middot; <?= e($activeFilter['location']) ?><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
  <div class="card mb-3">
    <div class="card-header"><?= $editHouse ? 'Edit House' : 'Add House' ?></div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="existing_id" value="<?= e($editHouse['id'] ?? '') ?>">
        <div class="col-md-3">
          <label class="form-label">House ID (VARCHAR)</label>
          <input class="form-control" name="id" required value="<?= e($editHouse['id'] ?? '') ?>" placeholder="e.g. A101">
          <div class="form-text">Manually assigned: H1, A101, BLOCK-A-01 ...</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Property / Building</label>
          <select class="form-select" name="property_id">
            <option value="">— Unassigned —</option>
            <?php $selProp = (int) ($editHouse['property_id'] ?? ($filterProp ?? 0)); ?>
            <?php foreach ($properties as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $selProp === (int) $p['id'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?><?= $p['location'] ? ' (' . e($p['location']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Rent Amount</label>
          <input type="number" step="0.01" min="0" class="form-control" name="rent_amount" required value="<?= e($editHouse['rent_amount'] ?? '') ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-dark"><i class="bi bi-save"></i> Save House</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>All Houses (<?= count($houses) ?>)</span>
    <?php if ($action === 'list'): ?>
      <a class="btn btn-sm btn-dark" href="<?= e(base_url('admin/houses.php?action=add' . ($filterProp !== null ? '&property_id=' . $filterProp : ''))) ?>"><i class="bi bi-plus-lg"></i> Add House</a>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>House ID</th><th>Property</th><th class="text-end">Rent</th><th>Status</th><th>Tenant</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($houses as $h): ?>
        <tr>
          <td><strong><?= e($h['id']) ?></strong></td>
          <td class="small"><?= $h['property_name'] ? e($h['property_name']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><?= e(money($h['rent_amount'])) ?></td>
          <td><?= status_badge($h['status']) ?></td>
          <td><?= e($h['tenant_name'] ?? '—') ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/houses.php?action=edit&id=' . urlencode($h['id']))) ?>"><i class="bi bi-pencil"></i></a>
            <?php if ($h['status'] === 'VACANT'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete house <?= e($h['id']) ?>?')">
              <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= e($h['id']) ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$houses): ?><tr><td colspan="6" class="text-center text-muted py-4">No houses registered.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
