<?php
/*
 * Mini installer RDM: unggah file ini saja (sebagai index.php) ke folder hosting yang masih kosong, lalu buka di browser.
 * Langkah: periksa server -> unduh paket hosting dari portal RDM -> ekstrak -> installer database RDM.
 * Installer & paket zip menghapus dirinya sendiri setelah selesai. Kode tetap kompatibel PHP 7.2.
 */

// ===== Pengaturan versi =====
$RDM = array(
	"nama"       => "RDM v4",
	"php_min"    => "7.4.0",
	"php_maks"   => "8.4.99",   // batas atas (inklusif)
	"php_label"  => "7.4 – 8.4",
	"ioncube_min"=> 15,         // versi minimal ionCube Loader yang disarankan (0 = cukup aktif)
	"paket"      => "https://rdm.hdmadrasah.id/download/hosting4",
);
// ============================

ini_set("max_execution_time", "3600");
ini_set("default_socket_timeout", "3600");
@set_time_limit(3600);

$DIR = __DIR__ . DIRECTORY_SEPARATOR;
$ZIP = $DIR . "rdm-paket.zip";
$PENGEKSTRAK = "rdm-installer.php";

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8"); }

// Syarat server: [label, ok, keterangan, wajib]
function cek_server($RDM, $DIR) {
	$hasil = array();
	$php = PHP_VERSION;
	$okPhp = version_compare($php, $RDM["php_min"], ">=") && version_compare($php, $RDM["php_maks"], "<=");
	$hasil[] = array("PHP " . $RDM["php_label"], $okPhp, "Terpasang PHP " . $php, true);

	$ion = extension_loaded("ionCube Loader");
	$verIon = ($ion && function_exists("ioncube_loader_version")) ? ioncube_loader_version() : "";
	$hasil[] = array("ionCube Loader aktif", $ion, $ion ? "Versi " . $verIon : "Aktifkan ionCube Loader di pengaturan PHP hosting", true);
	if ($ion && $RDM["ioncube_min"] > 0) {
		$okVer = (int) $verIon >= (int) $RDM["ioncube_min"];
		$hasil[] = array("ionCube Loader versi " . $RDM["ioncube_min"] . " ke atas", $okVer, $okVer ? "Versi " . $verIon : "Disarankan memperbarui ionCube Loader", false);
	}
	$hasil[] = array("Ekstensi zip", class_exists("ZipArchive"), class_exists("ZipArchive") ? "Tersedia" : "Aktifkan ekstensi zip PHP", true);
	$hasil[] = array("Ekstensi cURL", function_exists("curl_init"), function_exists("curl_init") ? "Tersedia" : "Aktifkan ekstensi curl PHP", true);
	// ekstensi tambahan versi ini (pengaturan "ekstensi", mis. intl & mbstring untuk RDM v4.1 / CodeIgniter 4)
	foreach (isset($RDM["ekstensi"]) ? $RDM["ekstensi"] : array() as $ext) {
		$ada = extension_loaded($ext);
		$hasil[] = array("Ekstensi " . $ext, $ada, $ada ? "Tersedia" : "Aktifkan ekstensi " . $ext . " PHP", true);
	}
	$tulis = is_writable($DIR);
	$hasil[] = array("Folder dapat ditulis", $tulis, $tulis ? "Siap" : "Ubah izin folder agar dapat ditulis PHP", true);
	return $hasil;
}
function server_siap($cek) {
	foreach ($cek as $c) {
		if ($c[3] && !$c[1]) return false;
	}
	return true;
}

// Unduh paket dengan cURL (verifikasi SSL aktif) langsung ke file
function unduh_paket($url, $tujuan, &$pesan) {
	$sementara = $tujuan . ".part";
	@unlink($sementara);
	$fp = @fopen($sementara, "wb");
	if (!$fp) { $pesan = "File sementara tidak dapat dibuat di folder ini."; return false; }
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_FILE => $fp,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_TIMEOUT => 3000,
		CURLOPT_FAILONERROR => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_USERAGENT => "RDM Mini Installer",
	));
	$ok = curl_exec($ch);
	$kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err = curl_error($ch);
	curl_close($ch);
	fclose($fp);
	if (!$ok || $kode >= 400) {
		@unlink($sementara);
		$pesan = $kode == 404 ? "Paket belum tersedia di portal RDM." : "Gagal mengunduh paket (" . ($err !== "" ? $err : "HTTP " . $kode) . ").";
		return false;
	}
	if (!rename($sementara, $tujuan)) {
		@unlink($sementara);
		$pesan = "Paket tidak dapat disimpan.";
		return false;
	}
	return true;
}

// Paket harus zip RDM: berisi index.php, tanpa config.php, tanpa path keluar folder
function periksa_zip($file, &$pesan) {
	$zip = new ZipArchive;
	if ($zip->open($file) !== true) { $pesan = "File unduhan bukan zip yang valid."; return false; }
	$adaIndex = false;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$n = str_replace("\\", "/", (string) $zip->getNameIndex($i));
		if ($n === "" || $n[0] === "/" || preg_match('#^[A-Za-z]:#', $n) || strpos($n, "../") !== false) {
			$zip->close(); $pesan = "Paket berisi path yang tidak diizinkan."; return false;
		}
		if ($n === "index.php") $adaIndex = true;
		if ($n === "config.php") { $zip->close(); $pesan = "Paket tidak sesuai (berisi config.php)."; return false; }
	}
	$zip->close();
	if (!$adaIndex) { $pesan = "Paket tidak sesuai (index.php tidak ditemukan)."; return false; }
	return true;
}

$langkah = isset($_POST["langkah"]) ? $_POST["langkah"] : (isset($_GET["langkah"]) ? $_GET["langkah"] : "");
$terpasang = file_exists($DIR . "config.php");
$cek = cek_server($RDM, $DIR);
$siap = server_siap($cek);
$tahap = 1;              // 1 periksa, 2 unduh, 3 ekstrak, 4 selesai
$status = "";            // sukses | gagal
$pesan = "";
$alihkan = "";           // URL tujuan otomatis

if ($terpasang) {
	$status = "gagal";
	$pesan = "RDM sudah terpasang di folder ini (config.php ditemukan). Installer tidak dijalankan agar data tidak tertimpa.";
} elseif ($langkah === "unduh" && $_SERVER["REQUEST_METHOD"] === "POST") {
	$tahap = 2;
	if (!$siap) {
		$status = "gagal"; $pesan = "Syarat server belum terpenuhi.";
	} elseif (unduh_paket($RDM["paket"], $ZIP, $pesan) && periksa_zip($ZIP, $pesan)) {
		// salinan installer untuk langkah ekstrak (index.php ini akan tertimpa index.php RDM)
		if (basename(__FILE__) === $PENGEKSTRAK || copy(__FILE__, $DIR . $PENGEKSTRAK)) {
			$status = "sukses";
			$pesan = "Paket terunduh (" . number_format(filesize($ZIP) / 1048576, 1, ",", ".") . " MB). Melanjutkan ke ekstrak...";
			$alihkan = $PENGEKSTRAK . "?langkah=ekstrak";
		} else {
			$status = "gagal"; $pesan = "Installer tidak dapat menyalin dirinya untuk langkah ekstrak.";
		}
	} else {
		$status = "gagal";
		@unlink($ZIP);
	}
} elseif ($langkah === "ekstrak") {
	$tahap = 3;
	if (!file_exists($ZIP)) {
		$status = "gagal"; $pesan = "Paket tidak ditemukan. Ulangi dari awal.";
	} elseif (!periksa_zip($ZIP, $pesan)) {
		$status = "gagal"; @unlink($ZIP);
	} else {
		$zip = new ZipArchive;
		if ($zip->open($ZIP) === true && $zip->extractTo($DIR)) {
			$zip->close();
			@unlink($ZIP);
			$tahap = 4;
			$status = "sukses";
			$pesan = "RDM berhasil diekstrak. Selanjutnya isi pengaturan database di halaman instalasi RDM.";
			$alihkan = "./";
			if (basename(__FILE__) === $PENGEKSTRAK) @unlink(__FILE__);
		} else {
			$status = "gagal"; $pesan = "Paket gagal diekstrak. Pastikan folder dapat ditulis.";
		}
	}
}
$judulTahap = array(1 => "Periksa server", 2 => "Unduh paket", 3 => "Ekstrak", 4 => "Selesai");
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mini Installer <?php echo h($RDM["nama"]); ?></title>
<?php if ($alihkan !== "") { ?><meta http-equiv="refresh" content="3;url=<?php echo h($alihkan); ?>"><?php } ?>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
	font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2933;
	background: linear-gradient(160deg, #0f7a3a 0%, #0b5c2c 45%, #eef3f0 45%); }
.kartu { width: 100%; max-width: 540px; background: #fff; border-radius: 16px; box-shadow: 0 20px 50px rgba(0, 0, 0, .18); overflow: hidden; }
.kepala { padding: 26px 28px 18px; text-align: center; }
.logo { display: inline-flex; gap: 4px; margin-bottom: 10px; }
.logo span { width: 34px; height: 34px; border-radius: 8px; color: #fff; font-weight: 800; font-size: 19px; line-height: 34px; }
.logo span:nth-child(1) { background: #e02424; } .logo span:nth-child(2) { background: #1d4ed8; } .logo span:nth-child(3) { background: #0f7a3a; }
.kepala h1 { margin: 0; font-size: 21px; }
.kepala p { margin: 4px 0 0; color: #616e7c; font-size: 14px; }
.tahap { display: flex; list-style: none; margin: 0; padding: 0 20px 18px; gap: 6px; }
.tahap li { flex: 1; text-align: center; font-size: 11.5px; color: #9aa5b1; }
.tahap li b { display: block; height: 4px; border-radius: 4px; background: #e4e7eb; margin-bottom: 6px; }
.tahap li.aktif { color: #0f7a3a; font-weight: 600; } .tahap li.aktif b, .tahap li.lewat b { background: #0f7a3a; }
.isi { padding: 6px 28px 28px; }
.syarat { list-style: none; margin: 0 0 18px; padding: 0; border: 1px solid #e4e7eb; border-radius: 12px; }
.syarat li { display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px; border-top: 1px solid #eef1f4; }
.syarat li:first-child { border-top: none; }
.tanda { flex: 0 0 22px; height: 22px; border-radius: 50%; color: #fff; font-size: 13px; font-weight: 700; text-align: center; line-height: 22px; margin-top: 1px; }
.tanda.ya { background: #0f7a3a; } .tanda.tidak { background: #e02424; } .tanda.saran { background: #e8a33d; }
.syarat strong { display: block; font-size: 14px; } .syarat small { color: #616e7c; }
.pesan { border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; font-size: 14px; }
.pesan.sukses { background: #e8f5ee; color: #0b5c2c; border: 1px solid #b7dfc6; }
.pesan.gagal { background: #fdecec; color: #9b1c1c; border: 1px solid #f5c2c2; }
.tombol { display: block; width: 100%; border: none; border-radius: 10px; padding: 13px 18px; font: inherit; font-weight: 600; font-size: 16px;
	text-align: center; text-decoration: none; cursor: pointer; background: #0f7a3a; color: #fff; }
.tombol:hover { background: #0b5c2c; }
.tombol[disabled] { background: #c6ccd2; cursor: not-allowed; }
.tombol.kedua { background: #fff; color: #0f7a3a; border: 1px solid #0f7a3a; margin-top: 10px; }
.proses { display: none; text-align: center; color: #616e7c; font-size: 14px; margin-top: 14px; }
.putar { width: 26px; height: 26px; border: 3px solid #d7eadf; border-top-color: #0f7a3a; border-radius: 50%; margin: 0 auto 8px; animation: putar 1s linear infinite; }
@keyframes putar { to { transform: rotate(360deg); } }
.catatan { margin-top: 18px; font-size: 12.5px; color: #7b8794; text-align: center; }
</style>
</head>
<body>
<div class="kartu">
	<div class="kepala">
		<div class="logo"><span>R</span><span>D</span><span>M</span></div>
		<h1>Mini Installer <?php echo h($RDM["nama"]); ?></h1>
		<p>Rapor Digital Madrasah untuk hosting PHP <?php echo h($RDM["php_label"]); ?></p>
	</div>
	<ol class="tahap">
		<?php foreach ($judulTahap as $no => $judul) { ?>
		<li class="<?php echo $no == $tahap ? "aktif" : ($no < $tahap ? "lewat" : ""); ?>"><b></b><?php echo h($judul); ?></li>
		<?php } ?>
	</ol>
	<div class="isi">
		<?php if ($pesan !== "") { ?>
		<div class="pesan <?php echo h($status); ?>"><?php echo h($pesan); ?></div>
		<?php } ?>

		<?php if ($tahap == 1 && !$terpasang) { ?>
		<ul class="syarat">
			<?php foreach ($cek as $c) { $kelas = $c[1] ? "ya" : ($c[3] ? "tidak" : "saran"); ?>
			<li><span class="tanda <?php echo $kelas; ?>"><?php echo $c[1] ? "&#10003;" : ($c[3] ? "&#10005;" : "!"); ?></span>
				<div><strong><?php echo h($c[0]); ?></strong><small><?php echo h($c[2]); ?></small></div></li>
			<?php } ?>
		</ul>
		<form method="post" id="formInstall">
			<input type="hidden" name="langkah" value="unduh">
			<button type="submit" class="tombol" id="tombolInstall" <?php echo $siap ? "" : "disabled"; ?>>Install <?php echo h($RDM["nama"]); ?></button>
		</form>
		<div class="proses" id="proses"><div class="putar"></div>Mengunduh paket RDM, mohon tunggu. Jangan tutup halaman ini.</div>
		<?php if (!$siap) { ?><p class="catatan">Lengkapi syarat bertanda merah, lalu muat ulang halaman ini.</p><?php } ?>
		<?php } elseif ($status === "gagal" && !$terpasang) { ?>
		<a class="tombol" href="<?php echo h(basename(__FILE__)); ?>">Ulangi</a>
		<?php } elseif ($alihkan !== "") { ?>
		<a class="tombol" href="<?php echo h($alihkan); ?>"><?php echo $tahap == 4 ? "Lanjut ke instalasi database" : "Lanjutkan"; ?></a>
		<?php } ?>
		<p class="catatan">Paket diunduh dari portal resmi RDM. Installer ini terhapus otomatis setelah selesai.</p>
	</div>
</div>
<script>
var f = document.getElementById("formInstall");
if (f) {
	f.addEventListener("submit", function () {
		document.getElementById("tombolInstall").disabled = true;
		document.getElementById("proses").style.display = "block";
	});
}
</script>
</body>
</html>
