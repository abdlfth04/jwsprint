<?php
require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
requireLogin();
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID produksi tidak valid.'); }
if (!canAccessProductionRecord($id)) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke dokumen produksi ini.');
}

$stmtDok = $conn->prepare(
    "SELECT pr.*, t.no_transaksi, t.created_at as tgl_invoice,
            p.nama as nama_pelanggan, p.telepon as tlp_pelanggan,
            k.nama as nama_karyawan, k.jabatan,
            u.nama as nama_user,
            dt.nama_produk, dt.kategori_tipe, dt.satuan, dt.qty, dt.lebar, dt.tinggi, dt.luas,
            dt.harga, dt.finishing_nama, dt.finishing_biaya, dt.bahan_nama, dt.size_detail, dt.subtotal, dt.catatan as catatan_item
    FROM produksi pr
    LEFT JOIN transaksi t ON pr.transaksi_id=t.id
    LEFT JOIN pelanggan p ON t.pelanggan_id=p.id
    LEFT JOIN karyawan k ON pr.karyawan_id=k.id
    LEFT JOIN users u ON pr.user_id=u.id
    LEFT JOIN detail_transaksi dt ON pr.detail_transaksi_id=dt.id
    WHERE pr.id = ?
    LIMIT 1"
);
$dok = null;
if ($stmtDok) {
    $stmtDok->bind_param('i', $id);
    $stmtDok->execute();
    $dok = $stmtDok->get_result()->fetch_assoc();
    $stmtDok->close();
}

if (!$dok) { echo '<p class="text-center text-muted">Data tidak ditemukan.</p>'; exit; }

$setting = $conn->query("SELECT * FROM setting WHERE id=1")->fetch_assoc();
$isJO    = $dok['tipe_dokumen'] === 'JO';
$color   = $isJO ? '#4f46e5' : '#f59e0b';
?>
<div style="font-family:'Segoe UI',sans-serif;font-size:.9rem;padding:10px" id="printArea">
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid <?= $color ?>;padding-bottom:12px;margin-bottom:16px">
        <div>
            <div style="font-size:1.4rem;font-weight:700;color:<?= $color ?>"><?= $dok['tipe_dokumen'] ?></div>
            <div style="font-size:.8rem;color:#64748b"><?= $isJO ? 'Job Order' : 'Surat Perintah Kerja' ?></div>
        </div>
        <div style="text-align:right">
            <div style="font-weight:700"><?= htmlspecialchars($setting['nama_toko']) ?></div>
            <div style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($setting['alamat']) ?></div>
            <div style="font-size:.8rem;color:#64748b"><?= htmlspecialchars($setting['telepon']) ?></div>
        </div>
    </div>

    <!-- Info Dokumen -->
    <table style="width:100%;margin-bottom:16px;font-size:.85rem">
        <tr>
            <td style="width:50%;vertical-align:top">
                <table>
                    <tr><td style="color:#64748b;width:120px">No. Dokumen</td><td><strong><?= htmlspecialchars($dok['no_dokumen']) ?></strong></td></tr>
                    <tr><td style="color:#64748b">Tanggal</td><td><?= date('d M Y', strtotime($dok['tanggal'])) ?></td></tr>
                    <?php if ($dok['deadline']): ?>
                    <tr><td style="color:#64748b">Deadline</td><td style="color:#ef4444;font-weight:600"><?= date('d M Y', strtotime($dok['deadline'])) ?></td></tr>
                    <?php endif; ?>
                    <tr><td style="color:#64748b">Invoice</td><td><?= htmlspecialchars($dok['no_transaksi'] ?? '-') ?></td></tr>
                    <tr><td style="color:#64748b">Status</td><td><span style="background:<?= $color ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:.75rem"><?= strtoupper($dok['status']) ?></span></td></tr>
                </table>
            </td>
            <td style="vertical-align:top">
                <table>
                    <tr><td style="color:#64748b;width:120px">Pelanggan</td><td><strong><?= htmlspecialchars($dok['nama_pelanggan'] ?? 'Umum') ?></strong></td></tr>
                    <?php if ($dok['tlp_pelanggan']): ?>
                    <tr><td style="color:#64748b">Telepon</td><td><?= htmlspecialchars($dok['tlp_pelanggan']) ?></td></tr>
                    <?php endif; ?>
                    <tr><td style="color:#64748b">Dikerjakan</td><td><?= htmlspecialchars($dok['nama_karyawan'] ?? 'Belum ditugaskan') ?></td></tr>
                    <?php if ($dok['jabatan']): ?>
                    <tr><td style="color:#64748b">Jabatan</td><td><?= htmlspecialchars($dok['jabatan']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <!-- Detail Pekerjaan -->
    <div style="background:#f8fafc;border-radius:8px;padding:14px;margin-bottom:16px">
        <div style="font-weight:700;margin-bottom:10px;color:<?= $color ?>">Detail Pekerjaan</div>
        <table style="width:100%;font-size:.85rem">
            <tr><td style="color:#64748b;width:140px">Nama Pekerjaan</td><td><strong><?= htmlspecialchars($dok['nama_pekerjaan']) ?></strong></td></tr>
            <?php if ($dok['nama_produk']): ?>
            <tr><td style="color:#64748b">Produk</td><td><?= htmlspecialchars($dok['nama_produk']) ?></td></tr>
            <tr><td style="color:#64748b">Tipe</td><td><?= strtoupper($dok['kategori_tipe']) ?></td></tr>
            <?php if ($dok['luas'] > 0): ?>
            <tr><td style="color:#64748b">Dimensi</td><td><?= $dok['lebar'] ?> ? <?= $dok['tinggi'] ?> m = <strong><?= $dok['luas'] ?> m?</strong></td></tr>
            <?php else: ?>
            <tr><td style="color:#64748b">Qty</td><td><strong><?= $dok['qty'] ?> <?= $dok['satuan'] ?></strong></td></tr>
            <?php endif; ?>
            <?php if ($dok['bahan_nama']): ?>
            <tr><td style="color:#64748b">Bahan</td><td><?= htmlspecialchars($dok['bahan_nama']) ?></td></tr>
            <?php endif; ?>
            <?php if ($dok['size_detail']): ?>
            <tr><td style="color:#64748b">Ukuran</td><td><?= htmlspecialchars($dok['size_detail']) ?></td></tr>
            <?php endif; ?>
            <?php if ($dok['finishing_nama']): ?>
            <tr><td style="color:#64748b">Finishing</td><td><?= htmlspecialchars($dok['finishing_nama']) ?></td></tr>
            <?php endif; ?>
            <?php if ($dok['catatan_item']): ?>
            <tr><td style="color:#64748b">Catatan</td><td><?= htmlspecialchars($dok['catatan_item']) ?></td></tr>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($dok['keterangan']): ?>
            <tr><td style="color:#64748b">Instruksi</td><td><?= nl2br(htmlspecialchars($dok['keterangan'])) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- TTD -->
    <div style="display:flex;justify-content:space-between;margin-top:30px;font-size:.85rem">
        <div style="text-align:center;width:45%">
            <div style="margin-bottom:50px">Dibuat oleh,</div>
            <div style="border-top:1px solid #334155;padding-top:6px"><?= htmlspecialchars($dok['nama_user'] ?? '') ?></div>
        </div>
        <div style="text-align:center;width:45%">
            <div style="margin-bottom:50px">Dikerjakan oleh,</div>
            <div style="border-top:1px solid #334155;padding-top:6px"><?= htmlspecialchars($dok['nama_karyawan'] ?? '_______________') ?></div>
        </div>
    </div>
</div>
