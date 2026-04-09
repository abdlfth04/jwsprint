<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireRole('superadmin', 'admin');
payrollScheduleSupportReady($conn);

function slipPaymentStatusClass(string $status): string
{
    return strtolower(trim($status)) === 'sudah_dibayar' ? 'success' : 'warning';
}

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . pageUrl('penggajian.php'));
    exit;
}

$hasSlipGaji = schemaTableExists($conn, 'slip_gaji');
if (!$hasSlipGaji) {
    echo '<p style="font-family:sans-serif;padding:20px">Tabel slip_gaji belum ada.</p>';
    exit;
}

$hasKarNewCols = schemaColumnExists($conn, 'karyawan', 'nik');
$hasMetodeGajiCol = schemaColumnExists($conn, 'karyawan', 'metode_gaji');
$metodeGajiSelect = $hasMetodeGajiCol ? 'k.metode_gaji AS metode_gaji_karyawan' : 'NULL AS metode_gaji_karyawan';
$sql = $hasKarNewCols
    ? "SELECT sg.*, k.nama AS nama_karyawan, k.jabatan, k.nik, k.divisi, $metodeGajiSelect
       FROM slip_gaji sg
       JOIN karyawan k ON sg.karyawan_id = k.id
       WHERE sg.id = ?"
    : "SELECT sg.*, k.nama AS nama_karyawan, k.jabatan, NULL AS nik, NULL AS divisi, $metodeGajiSelect
       FROM slip_gaji sg
       JOIN karyawan k ON sg.karyawan_id = k.id
       WHERE sg.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$slip = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$slip) {
    echo '<p style="font-family:sans-serif;padding:20px">Slip gaji tidak ditemukan.</p>';
    exit;
}

$setting = $conn->query("SELECT * FROM setting WHERE id = 1")->fetch_assoc() ?: [];
$dibayarOlehNama = '';
if (!empty($slip['dibayar_oleh'])) {
    $stmtUser = $conn->prepare("SELECT nama FROM users WHERE id = ? LIMIT 1");
    if ($stmtUser) {
        $userId = (int) $slip['dibayar_oleh'];
        $stmtUser->bind_param('i', $userId);
        $stmtUser->execute();
        $rowUser = $stmtUser->get_result()->fetch_assoc();
        $stmtUser->close();
        $dibayarOlehNama = (string) ($rowUser['nama'] ?? '');
    }
}

$companyName = (string) ($setting['nama_toko'] ?? 'JWS Printing & Apparel');
$potongan = (float) ($slip['potongan'] ?? 0);
$totalGaji = (float) ($slip['total_gaji'] ?? 0);
$statusBayar = (string) ($slip['status_bayar'] ?? 'belum_dibayar');
$metodeSlip = payrollNormalizeMethod((string) ($slip['metode_gaji'] ?? ($slip['metode_gaji_karyawan'] ?? 'bulanan')));
$jadwalBayarSlip = trim((string) ($slip['jadwal_bayar'] ?? ''));
if ($jadwalBayarSlip === '') {
    $jadwalBayarSlip = payrollResolveScheduledPayDate($metodeSlip, (string) ($slip['periode_selesai'] ?? date('Y-m-d')));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji - <?= htmlspecialchars((string) ($slip['nama_karyawan'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= assetUrl('css/print_professional.css') ?>">
    <style>
        @page {
            size: A4 landscape;
            margin: 8mm;
        }
    </style>
</head>
<body class="print-document landscape">
    <div class="print-sheet">
        <div class="sheet-inner">
            <header class="document-banner">
                <div class="document-banner-main">
                    <?php if (!empty($setting['logo'])): ?>
                        <div class="document-logo">
                            <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars((string) $setting['logo']) ?>" alt="Logo perusahaan">
                        </div>
                    <?php endif; ?>
                    <div class="document-company">
                        <div class="document-eyebrow">Payroll</div>
                        <div class="document-company-name"><?= htmlspecialchars($companyName) ?></div>
                        <div class="document-company-meta">
                            <?php if (!empty($setting['alamat'])): ?><div><?= htmlspecialchars((string) $setting['alamat']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['telepon'])): ?><div>Telp: <?= htmlspecialchars((string) $setting['telepon']) ?></div><?php endif; ?>
                            <?php if (!empty($setting['email'])): ?><div>Email: <?= htmlspecialchars((string) $setting['email']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <aside class="document-meta-card">
                    <div class="meta-title">Informasi Slip</div>
                    <div class="meta-stack">
                        <div class="meta-line">
                            <span class="meta-line-label">No. Slip</span>
                            <span class="meta-line-value">#<?= str_pad((string) ($slip['id'] ?? 0), 5, '0', STR_PAD_LEFT) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Periode</span>
                            <span class="meta-line-value"><?= htmlspecialchars(date('d M Y', strtotime((string) ($slip['periode_mulai'] ?? 'now')))) ?> - <?= htmlspecialchars(date('d M Y', strtotime((string) ($slip['periode_selesai'] ?? 'now')))) ?></span>
                        </div>
                        <div class="meta-line">
                            <span class="meta-line-label">Tanggal Cetak</span>
                            <span class="meta-line-value"><?= htmlspecialchars(date('d M Y')) ?></span>
                        </div>
                    </div>
                </aside>
            </header>

            <section class="document-heading">
                <div>
                    <h1>Slip Gaji</h1>
                    <p>Rincian penghasilan dan status pembayaran karyawan untuk periode terpilih.</p>
                </div>
                <div class="document-badges">
                    <span class="status-chip <?= slipPaymentStatusClass($statusBayar) ?>">
                        <?= htmlspecialchars($statusBayar === 'sudah_dibayar' ? 'Sudah Dibayar' : 'Belum Dibayar') ?>
                    </span>
                </div>
            </section>

            <section class="info-panel-grid two-col">
                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Data Karyawan</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Nama</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($slip['nama_karyawan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Jabatan</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($slip['jabatan'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">NIK</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($slip['nik'] ?? '-')) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Divisi</span>
                                <span class="kv-value"><?= htmlspecialchars((string) ($slip['divisi'] ?? '-')) ?></span>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="info-card avoid-break">
                    <div class="info-card-head"><strong>Status Pembayaran</strong></div>
                    <div class="card-body">
                        <div class="kv-grid">
                            <div class="kv-item">
                                <span class="kv-label">Status</span>
                                <span class="kv-value"><?= htmlspecialchars($statusBayar === 'sudah_dibayar' ? 'Sudah dibayar' : 'Belum dibayar') ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Metode Payroll</span>
                                <span class="kv-value"><?= htmlspecialchars(payrollMethodLabel($metodeSlip)) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Tanggal Bayar</span>
                                <span class="kv-value"><?= !empty($slip['tgl_bayar']) ? htmlspecialchars(date('d M Y', strtotime((string) $slip['tgl_bayar']))) : '-' ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Jadwal Bayar</span>
                                <span class="kv-value"><?= htmlspecialchars(date('d M Y', strtotime($jadwalBayarSlip))) ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Dibayar Oleh</span>
                                <span class="kv-value"><?= htmlspecialchars($dibayarOlehNama !== '' ? $dibayarOlehNama : '-') ?></span>
                            </div>
                            <div class="kv-item">
                                <span class="kv-label">Gaji Bersih</span>
                                <span class="kv-value">Rp <?= number_format($totalGaji, 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                </article>
            </section>

            <section class="summary-panel-grid two-col">
                <article class="summary-card avoid-break">
                    <div class="summary-card-head"><strong>Komponen Penghasilan</strong></div>
                    <div class="card-body">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Komponen</th>
                                    <th class="text-right" style="width: 34%;">Nilai</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Gaji Pokok</td>
                                    <td class="text-right">Rp <?= number_format((float) ($slip['gaji_pokok'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                                <tr>
                                    <td>Tunjangan</td>
                                    <td class="text-right">Rp <?= number_format((float) ($slip['tunjangan'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                                <tr>
                                    <td>Bonus</td>
                                    <td class="text-right">Rp <?= number_format((float) ($slip['bonus'] ?? 0), 0, ',', '.') ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="summary-card totals-card avoid-break">
                    <div class="summary-card-head"><strong>Ringkasan Slip</strong></div>
                    <div class="card-body">
                        <div class="totals-row"><span>Total Penghasilan</span><strong>Rp <?= number_format(((float) ($slip['gaji_pokok'] ?? 0)) + ((float) ($slip['tunjangan'] ?? 0)) + ((float) ($slip['bonus'] ?? 0)), 0, ',', '.') ?></strong></div>
                        <div class="totals-row <?= $potongan > 0 ? 'negative' : '' ?>"><span>Potongan Alpha</span><strong><?= $potongan > 0 ? '- Rp ' . number_format($potongan, 0, ',', '.') : 'Rp 0' ?></strong></div>
                        <div class="totals-row total"><span>Gaji Bersih</span><strong>Rp <?= number_format($totalGaji, 0, ',', '.') ?></strong></div>
                    </div>
                </article>
            </section>

            <section class="note-card avoid-break">
                <div class="note-card-head"><strong>Catatan Payroll</strong></div>
                <div class="card-body">
                    <div class="lead-note">
                        Slip ini merupakan dokumen internal perusahaan. Mohon disimpan sebagai arsip pembayaran karyawan untuk periode
                        <?= htmlspecialchars(date('d M Y', strtotime((string) ($slip['periode_mulai'] ?? 'now')))) ?>
                        sampai
                        <?= htmlspecialchars(date('d M Y', strtotime((string) ($slip['periode_selesai'] ?? 'now')))) ?>.
                        Metode payroll karyawan ini adalah <?= htmlspecialchars(strtolower(payrollMethodLabel($metodeSlip))) ?> dengan jadwal pembayaran <?= htmlspecialchars(strtolower(payrollScheduleRuleLabel($metodeSlip))) ?>.
                    </div>
                </div>
            </section>

            <section class="signature-grid">
                <div class="signature-card">
                    <div class="signature-role">Menyetujui</div>
                    <div class="signature-name"><?= htmlspecialchars($companyName) ?></div>
                </div>
                <div class="signature-card">
                    <div class="signature-role">Penerima</div>
                    <div class="signature-name"><?= htmlspecialchars((string) ($slip['nama_karyawan'] ?? '-')) ?></div>
                </div>
            </section>

            <?php if (!empty($setting['footer_nota'])): ?>
                <div class="document-footer"><?= nl2br(htmlspecialchars((string) $setting['footer_nota'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <script>window.onload = function () { window.print(); };</script>
</body>
</html>
