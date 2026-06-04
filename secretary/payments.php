<?php
// ============================================================
// secretary/payments.php — Secretary Payment Portal
// Secretary has the same access as admin for payments only
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_role(ROLE_SECRETARY);

// The admin payments page already handles ROLE_SECRETARY
// Secretary cannot access any other admin modules
require __DIR__ . '/../admin/payments.php';
