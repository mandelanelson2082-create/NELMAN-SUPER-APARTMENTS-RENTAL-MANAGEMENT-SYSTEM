<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
// Reports are rendered inside the authenticated admin area.
require_admin();
redirect(base_url('admin/reports.php'));
