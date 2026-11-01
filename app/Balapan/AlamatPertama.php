<?php 
  
declare(strict_types=1); 
  
namespace App\Balapan; 
  
use App\Enums\NamaPeran; 
use App\Models\Alamat; 
use App\Models\Pengguna; 
use App\Services\PenerbitSesi; 
use App\Services\PengelolaPeran; 
use Illuminate\Support\Str; 
  
/** 
 * Seorang pembeli baru menyimpan alamat dari beberapa tab sekaligus. 
 * Aturannya: alamatnya tidak melebihi batas, dan tepat satu yang menjadi alamat utama. 
 */ 
final class AlamatPertama implements SkenarioBalapan 
{ 
    use PanggilHttp; 
  
    public function keterangan(): string 
    { 
        return 'Pembeli baru menyimpan alamat dari banyak tab: satu utama, tidak melebihi batas'; 
    } 
  
    public function proses(): int 
    { 
        return 8; 
    } 
  
    public function siapkan(): array 
    { 
        $pembeli = new Pengguna([ 
            'nama' => 'Pembeli Balapan', 
            'surel' => 'balapan-'.Str::lower(Str::random(10)).'@lapak.test', 
            'kata_sandi' => Str::random(24), 
        ]); 
        $pembeli->save(); 
        app(PengelolaPeran::class)->beri($pembeli, NamaPeran::Pembeli); 
  
        // Kunci diterbitkan SETELAH peran diberikan: pemberian peran mencabut sesi lama. 
        [, $kunci] = app(PenerbitSesi::class)->terbitkan($pembeli, 'Uji balapan'); 
  
        return ['pembeli' => $pembeli->id, 'kunci' => $kunci]; 

    } 
  
    public function jalankan(array $konteks, int $nomor): string 
    { 
        [$status, $json] = $this->panggil('POST', '/api/v1/alamat', [ 
            'label' => "Tab {$nomor}", 
            'nama_penerima' => 'Pembeli Balapan', 
            'telepon' => '081200000000', 
            'jalan' => "Jl. Balapan No. {$nomor}", 
            'kota' => 'Surakarta', 
            'kode_pos' => '57111', 
        ], ['Authorization' => 'Bearer '.$konteks['kunci']]); 
  
        return $this->ringkas($status, $json); 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $alamat = Alamat::query()->where('pengguna_id', $konteks['pembeli']); 
        $jumlah = (clone $alamat)->count(); 
        $utama = (clone $alamat)->where('utama', true)->count(); 
        $batas = config('lapak.transaksi.alamat_maksimum'); 
  
        $pelanggaran = []; 
        if ($utama !== 1) { 
            $pelanggaran[] = "Ada {$utama} alamat utama, seharusnya tepat 1."; 
        } 
        if ($jumlah > $batas) { 
            $pelanggaran[] = "Tersimpan {$jumlah} alamat, melebihi batas {$batas}."; 
        } 
  
        return $pelanggaran; 
    } 
} 
 
Sebelum mempercayai skenarionya, Bambang menjalankannya terhadap terkunci() yang kuncinya dicabut. Varian 
itu tersedia pada berkas pendamping, folder varian/B1-tanpa-kunci/. 
app/Services/BukuAlamat.php — beda varian tanpa kunci (-) dengan versi akhir (+) 
         return DB::transaction(function () use ($pemilik, $kerja) { 
-            // VARIAN TANPA KUNCI, hanya untuk memperlihatkan pelanggarannya di MySQL. 
-            $punya = Alamat::query()->milik($pemilik)->orderBy('id')->get(); 
+            Pengguna::query()->whereKey($pemilik->id)->lockForUpdate()->firstOrFail(); 
+ 
+            // Dibaca SETELAH gembok didapat, dan dengan kunci: pembacaan biasa di dalam transaksi 
+            // MySQL dapat mengembalikan keadaan sebelum permintaan lain selesai menulis. 
+            $punya = Alamat::query()->milik($pemilik)->orderBy('id')->lockForUpdate()->get();