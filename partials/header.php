<?php
/**
 * Shared HTML header.
 * Set before including:
 *   $pageTitle (string)
 *   $nav       ('admin' | 'tenant' | '')  -- which navbar to render
 */
require_once __DIR__ . '/../config/app.php';
$pageTitle = $pageTitle ?? APP_NAME;
$nav = $nav ?? '';
$appCssPath = __DIR__ . '/../assets/css/app.css';
$appCssVersion = is_file($appCssPath) ? (string) filemtime($appCssPath) : '1';
$adminShell = $nav === 'admin' && current_admin() !== null;
$tenantShell = $nav === 'tenant' && current_tenant() !== null;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= e(base_url('assets/css/app.css?v=' . $appCssVersion)) ?>" rel="stylesheet">
</head>
<body class="bg-light<?= !empty($authPage) ? ' auth-page' : '' ?>">
<?php if ($adminShell && ($admin = current_admin())): ?>
  <div class="admin-shell">
  <?php include __DIR__ . '/admin_nav.php'; ?>
  <div class="admin-content">
  <div class="admin-mobile-header d-md-none">
    <button class="btn btn-dark" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar" aria-label="Open admin menu">
      <i class="bi bi-list"></i>
    </button>
    <a class="navbar-brand fw-bold mb-0" href="<?= e(base_url('admin/dashboard.php')) ?>">
      <i class="bi bi-buildings"></i> <?= e(APP_NAME) ?>
    </a>
  </div>
<?php elseif ($nav === 'tenant' && ($tenant = current_tenant())): ?>
  <div class="tenant-shell">
  <?php include __DIR__ . '/tenant_nav.php'; ?>
  <div class="tenant-content">
  <header class="tenant-topbar">
    <button class="btn btn-outline-secondary tenant-menu-toggle d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#tenantSidebar" aria-controls="tenantSidebar" aria-label="Open tenant menu">
      <i class="bi bi-list"></i>
    </button>
    <button class="btn btn-outline-secondary tenant-collapse-toggle d-none d-md-inline-flex" type="button" data-tenant-sidebar-toggle aria-expanded="true" aria-controls="tenantSidebar" aria-label="Collapse sidebar">
      <i class="bi bi-layout-sidebar"></i>
    </button>
    <div class="tenant-page-heading"><span class="tenant-page-eyebrow">TENANT PORTAL</span><strong><?= e($pageTitle) ?></strong></div>
    <div class="tenant-topbar-account"><i class="bi bi-person-circle"></i><span><?= e($tenant['name']) ?></span></div>
  </header>
<?php endif; ?>
<main class="<?= $tenantShell ? 'tenant-page-content container-fluid px-4 py-4' : ($nav ? 'container-fluid px-4 py-4' : 'container py-5') ?>">
<?= flash_render() ?>
