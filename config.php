<?php
// =====================================================================
//  RELOG VETO — opšta podešavanja
//
//  !!! NE UPISUJ OVDE LOZINKE !!!
//  Ovaj fajl je u gitu i svaki deploy ga pregazi.
//  Admin lozinka i tablet ključ idu SAMO u config.local.php na serveru
//  (šablon: config.local.example.php) — deploy taj fajl nikad ne dira.
// =====================================================================
$local = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
if (!is_array($local)) $local = [];

// Lozinka za /admin. Prazna = admin je zaključan dok se ne napravi config.local.php.
define('ADMIN_TOKEN', (string)($local['admin_token'] ?? ''));

// Ključ za tablet (/tablet?k=KLJUC). Prazan = tablet radi bez ključa.
define('TABLET_KEY', (string)($local['tablet_key'] ?? ''));

// Gde se čuva stanje veta i logoi (folderi moraju biti upisivi za PHP)
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');

// Maks. veličina logoa
define('MAX_LOGO_BYTES', 5 * 1024 * 1024);
