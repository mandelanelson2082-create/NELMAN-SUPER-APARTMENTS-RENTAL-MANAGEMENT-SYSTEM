</main>
<footer class="text-center text-muted small py-4">
  &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; Payments powered by Pesapal API 3.0
  <?php if (pesapal_is_simulate()): ?>
    <span class="badge bg-secondary ms-1">Pesapal: SIMULATE mode</span>
  <?php else: ?>
    <span class="badge bg-info ms-1">Pesapal: <?= e(ucfirst(pesapal_mode())) ?></span>
  <?php endif; ?>
</footer>
<?php if (!empty($tenantShell)): ?>
  </div>
  </div>
<?php endif; ?>
<?php if (!empty($adminShell)): ?>
  </div>
  </div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('[data-tenant-sidebar-toggle]').forEach((toggle) => {
  toggle.addEventListener('click', () => {
    const collapsed = document.querySelector('.tenant-shell').classList.toggle('tenant-shell--collapsed');
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?php if (!empty($pageScripts)) { echo $pageScripts; } ?>
</body>
</html>
