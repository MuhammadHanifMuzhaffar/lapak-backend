<?php 
  
declare(strict_types=1); 
  
namespace App\Exceptions; 
  
final class PenyesuaianStokDitolak extends GalatDomain 
{ 
    public static function akanNegatif(int $idProduk, string $nama, int $stok, int $selisih): self 
    { 
        $pesan = "Stok {$nama} tinggal {$stok}, tidak dapat dikurangi ".abs($selisih).'.'; 
  
        return new self('LP-4', $pesan, 409, [ 
            'produk_id' => $idProduk, 
            'stok_sekarang' => $stok, 
            'selisih' => $selisih, 
        ]); 
    } 
  
    public static function melewatiBatas(int $idProduk, string $nama, int $stok, int $batas): self 
    { 
        return new self('LP-4', "Stok {$nama} tidak boleh melebihi {$batas}.", 409, [ 
            'produk_id' => $idProduk, 
            'stok_sekarang' => $stok, 
            'batas' => $batas, 
        ]); 
    } 
} 
 
app/Http/Requests/PenyesuaianStokRequest.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Requests; 
  
use App\Services\PenjagaStok; 
use Illuminate\Foundation\Http\FormRequest; 
  
/** Satu-satunya masukan: berapa stok bertambah (positif) atau berkurang (negatif). */ 
final class PenyesuaianStokRequest extends FormRequest 
{ 
    public function authorize(): bool 
    { 
        return true; 
    } 
  
    public function rules(): array 
    { 
        $batas = PenjagaStok::MAKSIMUM; 
  
        return [ 
            'selisih' => ['required', 'integer', 'not_in:0', "between:-{$batas},{$batas}"], 
        ]; 
    } 

    /** @return array<string, string> */ 
    public function messages(): array 
    { 
        return [ 
            'selisih.not_in' => 'Selisih tidak boleh nol.', 
        ]; 
    } 
} 
 
app/Http/Requests/ProdukSayaRequest.php — perubahan B3 
  
 use App\Enums\StatusProduk; 
+use App\Services\PenjagaStok; 
 use Illuminate\Foundation\Http\FormRequest; 
 use Illuminate\Validation\Rule; 
   ... 
             // Batas atas mencegah luapan bilangan saat harga dikali kuantitas. 
             'harga_rupiah' => [$wajib, 'integer', 'between:100,1000000000'], 
-            'stok' => [$wajib, 'integer', 'between:0,100000'], 
+            // Stok hanya ditetapkan saat produk dibuat. Sesudahnya stok berubah lewat PenjagaStok: 
+            // angka mutlak dari layar penjual dapat menimpa penjualan yang terjadi setelah layar 
+            // itu dimuat (LP-4). 
+            'stok' => $this->isMethod('POST') 
+                ? ['required', 'integer', 'between:0,'.PenjagaStok::MAKSIMUM] 
+                : ['prohibited'], 
             'berat_gram' => ['sometimes', 'integer', 'between:0,100000'], 
             'status' => ['sometimes', Rule::enum(StatusProduk::class)], 
         ]; 
     } 
+ 
+    /** @return array<string, string> */ 
+    public function messages(): array 
+    { 
+        return [ 
+            'stok.prohibited' => 'Ubah stok dengan selisih lewat POST /toko-saya/produk/{id}/stok.', 
+        ]; 
+    } 
 } 
 
Stok tetap diterima saat produk dibuat, karena produk yang belum ada tidak dapat dibeli siapa pun. Setelah itu 
kolomnya tertutup bagi PATCH, dengan pesan yang menunjukkan pintu penggantinya. 
app/Http/Controllers/ProdukSayaController.php — perubahan B3 
  
 use App\Enums\StatusProduk; 
+use App\Http\Requests\PenyesuaianStokRequest; 
 use App\Http\Requests\ProdukSayaRequest; 
 use App\Http\Resources\ProdukSayaResource; 
 use App\Models\Produk; 
 use App\Models\Toko; 
+use App\Services\PenjagaStok; 
 use Illuminate\Http\JsonResponse; 
 use Illuminate\Http\Request; 
   ... 
     } 
  
+    /** 
+     * Penjual menyebut selisih, bukan angka akhir. Produk tetap dicari melalui toko miliknya, 
+     * lalu PenjagaStok membaca ulang barisnya di bawah kunci sebelum menghitung. 
+     */ 
+    public function stok(PenyesuaianStokRequest $request, PenjagaStok $stok, int $id): JsonResponse 
+    { 
+        $produk = $this->produkSaya($request, $id); 
+ 
+        return $this->sukses(ProdukSayaResource::make( 
+            $stok->sesuaikan($produk->id, $request->integer('selisih')), 
+        )); 
+    } 
+ 
     private function tokoSaya(Request $request): Toko 
     { 
 

routes/api/katalog.php — perubahan B3 
             Route::delete('produk/{id}', [ProdukSayaController::class, 'destroy']) 
                 ->name('produk.destroy'); 
+ 
+            // Mengubah stok: wajib Idempotency-Key (LP-11). "Tambah 5" yang diulang karena 
+            // jaringan putus tidak boleh menjadi tambah 10. 
+            Route::post('produk/{id}/stok', [ProdukSayaController::class, 'stok']) 
+                ->middleware('idempoten') 
+                ->name('produk.stok'); 
         }); 
     }); 
 
Pintu baru ini adalah rute kedua yang memasang idempoten, dan Bagas tidak menulis satu baris pun untuk itu. 
Middleware milik Sekar bekerja pada rute mana pun yang jawabannya berbentuk JSON. 
tests/Feature/Sistem/matriks/katalog.php — perubahan B3 
     'PATCH api/v1/toko-saya/produk/{id}' => ['penjual'], 
     'DELETE api/v1/toko-saya/produk/{id}' => ['penjual'], 
+    'POST api/v1/toko-saya/produk/{id}/stok' => ['penjual'], 
  
     'GET api/v1/admin/toko' => ['admin'], 
 
tests/Feature/Katalog/PenyesuaianStokTest.php — keadaan awal dan tiga uji pertama 
<?php 
  
use App\Exceptions\PenyesuaianStokDitolak; 
use App\Models\KunciIdempotensi; 
use App\Models\Pengguna; 
use App\Models\Produk; 
use App\Models\Toko; 
use App\Services\PenjagaStok; 
  
beforeEach(function () { 
    $this->sri = Pengguna::factory()->penjual()->create(); 
    $this->tokoSri = Toko::factory()->for($this->sri, 'pemilik')->create(); 
    $this->kain = Produk::factory()->for($this->tokoSri)->create([ 
        'nama' => 'Kain Batik Cap', 'stok' => 10, 
    ]); 
    $this->alamatStok = "/api/v1/toko-saya/produk/{$this->kain->id}/stok"; 
  
    $this->sesuaikan = fn (mixed $selisih, string $kunci = 'restok-kain-0001') => $this->postJson( 
        $this->alamatStok, ['selisih' => $selisih], ['Idempotency-Key' => $kunci], 
    ); 
}); 
  
it('menambah dan mengurangi stok dengan selisih', function () { 
    $this->sebagai($this->sri); 
  
    ($this->sesuaikan)(5)->assertOk()->assertJsonPath('data.stok', 15); 
    ($this->sesuaikan)(-12, 'koreksi-kain-0001')->assertOk()->assertJsonPath('data.stok', 3); 
  
    expect($this->kain->refresh()->stok)->toBe(3); 
}); 
  
it('menghitung dari stok terkini, bukan dari angka yang pernah dibaca', function () { 
    // Penjual memuat halaman ketika stok 10. Sebelum ia menekan simpan, tiga barang terjual. 
    $usang = Produk::findOrFail($this->kain->id); 
    Produk::whereKey($this->kain->id)->decrement('stok', 3); 
  
    $hasil = app(PenjagaStok::class)->sesuaikan($usang->id, 5); 
  
    expect($usang->stok)->toBe(10) 
        ->and($hasil->stok)->toBe(12) 
        ->and($this->kain->refresh()->stok)->toBe(12); 
}); 
  
it('menolak pengurangan yang membuat stok negatif dengan 409 (LP-4)', function () { 
    $this->sebagai($this->sri); 
  
    ($this->sesuaikan)(-11) 
        ->assertConflict() 

        ->assertJsonPath('galat.kode', 'LP-4') 
        ->assertJsonPath('galat.rincian.stok_sekarang', 10); 
  
    expect($this->kain->refresh()->stok)->toBe(10) 
        ->and(KunciIdempotensi::count())->toBe(0); 
}); 
 
tests/Feature/Katalog/PenyesuaianStokTest.php — idempotensi, produk toko lain, dan PATCH yang ditutup 
it('mewajibkan Idempotency-Key dan hanya menambah satu kali saat diulang (LP-11)', function () { 
    $this->sebagai($this->sri); 
  
    $this->postJson($this->alamatStok, ['selisih' => 5]) 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'LP-11'); 
  
    ($this->sesuaikan)(5)->assertOk()->assertHeaderMissing('Idempotency-Replayed'); 
    ($this->sesuaikan)(5) 
        ->assertOk() 
        ->assertHeader('Idempotency-Replayed', 'true') 
        ->assertJsonPath('data.stok', 15); 
  
    expect($this->kain->refresh()->stok)->toBe(15); 
}); 
  
it('menjawab 404 untuk produk toko lain dan tidak mengubah stoknya', function () { 
    $milikLain = Produk::factory()->create(['stok' => 7]); 
  
    $this->sebagai($this->sri) 
        ->postJson("/api/v1/toko-saya/produk/{$milikLain->id}/stok", ['selisih' => 5], [ 
            'Idempotency-Key' => 'restok-kain-0001', 
        ]) 
        ->assertNotFound(); 
  
    expect($milikLain->refresh()->stok)->toBe(7); 
}); 
  
it('tidak lagi menerima stok mutlak lewat PATCH produk', function () { 
    $alamat = "/api/v1/toko-saya/produk/{$this->kain->id}"; 
  
    $this->sebagai($this->sri) 
        ->patchJson($alamat, ['nama' => 'Kain Batik Tulis', 'stok' => 99]) 
        ->assertUnprocessable() 
        ->assertJsonPath( 
            'galat.rincian.stok.0', 
            'Ubah stok dengan selisih lewat POST /toko-saya/produk/{id}/stok.', 
        ); 
  
    expect($this->kain->refresh())->stok->toBe(10)->nama->toBe('Kain Batik Cap'); 
  
    $this->patchJson($alamat, ['nama' => 'Kain Batik Tulis']) 
        ->assertOk() 
        ->assertJsonPath('data.stok', 10); 
}); 
 
Uji kedua pada cuplikan pertama menirukan gambar D.5 di dalam Pest: sebuah salinan produk dimuat ketika 
stoknya 10, tiga barang terjual, lalu penyesuaian +5 diminta atas salinan lama. Hasilnya 12, bukan 15. 
9.5 Skenario kedua. 
app/Balapan/PenyesuaianStok.php — yang dijalankan tiap proses dan yang diperiksa 
    public function jalankan(array $konteks, int $nomor): string 
    { 
        $id = $konteks['produk']; 
  
        if ($nomor % 2 === 0) { 
            $this->penjaga->sesuaikan($id, self::RESTOK); 
  
            return 'penjual: tambah '.self::RESTOK; 
        } 
  
        try { 

            DB::transaction(function () use ($id): void { 
                $this->penjaga->kurangi($this->penjaga->ambil([$id])[$id], 1); 
            }); 
        } catch (ProdukTidakDapatDibeli) { 
            return 'pembeli: ditolak'; 
        } 
  
        return 'pembeli: beli 1'; 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $terbeli = count(array_filter($hasil, fn (string $h) => $h === 'pembeli: beli 1')); 
        $restok = count(array_filter($hasil, fn (string $h) => str_starts_with($h, 'penjual'))); 
        $seharusnya = self::STOK_AWAL - $terbeli + $restok * self::RESTOK; 
        $nyata = Produk::query()->findOrFail($konteks['produk'])->stok; 
  
        return $nyata === $seharusnya 
            ? [] 
            : ["Stok akhir {$nyata}, seharusnya {$seharusnya}: ada perubahan yang tertimpa."]; 
    } 
 
Varian pembandingnya, folder varian/B3-stok-mutlak/, mengganti satu baris sesuaikan() dengan perilaku 
lama: baca stok, tunggu 20 milidetik untuk mewakili jeda antara melihat dan menekan simpan, lalu tulis angka 
mutlak. 
Keluaran — varian B3-stok-mutlak 
penyesuaian-stok: Penjual menambah stok selagi pembeli berbelanja: tidak ada perubahan yang hilang 
+-------------------+---------------+ 
| Hasil             | Jumlah proses | 
+-------------------+---------------+ 
| pembeli: beli 1   | 6             | 
| penjual: tambah 3 | 6             | 
+-------------------+---------------+ 
PELANGGARAN: Stok akhir 11, seharusnya 22: ada perubahan yang tertimpa. 
 
Keluaran — versi akhir 
penyesuaian-stok: Penjual menambah stok selagi pembeli berbelanja: tidak ada perubahan yang hilang 
+-------------------+---------------+ 
| Hasil             | Jumlah proses | 
+-------------------+---------------+ 
| pembeli: beli 1   | 6             | 
| penjual: tambah 3 | 6             | 
+-------------------+---------------+ 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
 
Terminal — Bagas 
./vendor/bin/pint 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git add app/Services/PenjagaStok.php app/Exceptions/PenyesuaianStokDitolak.php \ 
        app/Http/Requests app/Http/Controllers/ProdukSayaController.php \ 
        routes/api/katalog.php tests/Feature/Sistem/matriks/katalog.php 
git commit -m "feat: penjual mengubah stok dengan selisih lewat penjaga stok (LP-4, LP-11)" 
git add tests/Feature/Katalog/PenyesuaianStokTest.php app/Balapan/PenyesuaianStok.php 
git commit -m "test: uji penyesuaian stok dan skenario penyesuaian-stok" 
git push -u origin sesi-4/kunci-stok-bagas 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 333 uji lulus. 
PERINGATAN — Perubahan ini memutus klien lama 
Klien yang masih mengirim stok lewat PATCH kini menerima 422. Itu perubahan kontrak, dan Bagas menuliskannya 
pada deskripsi PR di bawah judul "Perubahan yang memutus". Pada API yang sudah dipakai pihak lain, perubahan 

seperti ini memerlukan versi baru atau masa peralihan. Pada Lapak, satu -satunya klien adalah koleksi Postman tim, 
yang diperbarui pada Latihan 7. 
 
Langkah 10 — B4: Webhook Bertanda Tangan dan Dana Tertahan (Dimas) 
Cabang: sesi-4/webhook-dimas. Pekerjaan ini mengubah tagihan yang menunggu menjadi uang yang tercatat. 
10.1 Tanda tangan. 
app/Services/TandaTanganGateway.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
/** 
 * Memeriksa bahwa sebuah webhook benar-benar dikirim gerbang pembayaran. 
 * 
 * Pintu webhook tidak dapat memakai kunci sesi: pemanggilnya server lain, bukan pengguna. 
 * Sebagai gantinya, gerbang dan Lapak memegang satu rahasia bersama. Gerbang mengirim header 
 * 
 *     X-Gateway-Signature: t=<detik unix>,v1=<HMAC-SHA256 atas "<t>.<badan mentah>"> 
 * 
 * Siapa pun dapat mengirim JSON ke alamat ini. Hanya pemegang rahasia yang dapat menandatanganinya. 
 */ 
final class TandaTanganGateway 
{ 
    public const HEADER = 'X-Gateway-Signature'; 
  
    /** Dipakai simulasi gerbang dan uji. Di dunia nyata, gerbanglah yang menandatangani. */ 
    public function buat(string $badan, ?int $waktu = null, ?string $rahasia = null): string 
    { 
        $waktu ??= now()->getTimestamp(); 
        $rahasia ??= (string) config('lapak.gateway.rahasia'); 
  
        return "t={$waktu},v1=".hash_hmac('sha256', "{$waktu}.{$badan}", $rahasia); 
    } 
  
    public function sah(?string $header, string $badan): bool 
    { 
        $rahasia = (string) config('lapak.gateway.rahasia'); 
  
        // Gagal tertutup: rahasia yang lupa diisi menolak SEMUA webhook, bukan menerima semuanya. 
        if ($rahasia === '' || $header === null) { 
            return false; 
        } 
  
        if (preg_match('/\At=(\d{1,12}),v1=([0-9a-f]{64})\z/', $header, $bagian) !== 1) { 
            return false; 
        } 
  
        [, $waktu, $tanda] = $bagian; 
  
        // Webhook lama yang direkam orang di tengah jalan tidak dapat diputar ulang besok. 
        if (abs(now()->getTimestamp() - (int) $waktu) > config('lapak.gateway.toleransi_detik')) { 
            return false; 
        } 
  
        // hash_equals membandingkan dalam waktu tetap, seperti pada kunci sesi. 
        return hash_equals(hash_hmac('sha256', "{$waktu}.{$badan}", $rahasia), $tanda); 
    } 
} 
 
Metode sah() pendek, dan setiap barisnya menutup satu serangan pada tabel D.6. Urutannya pun berarti: rahasia 
kosong ditolak paling awal, sebelum ada HMAC yang dihitung, sehingga server yang salah konfigurasi tidak pernah 
membandingkan tanda tangan terhadap kunci kosong. 

config/lapak.php — blok yang ditambahkan 
     'pencairan_minimum_rupiah' => (int) env('LAPAK_PENCAIRAN_MINIMUM', 50000), 
  
+    // Gerbang pembayaran (disimulasikan). Rahasia bersama penanda tangan webhook: wajib diisi 
+    // lewat .env dan tidak pernah di-commit. Bila kosong, seluruh webhook ditolak. 
+    'gateway' => [ 
+        'rahasia' => env('LAPAK_GATEWAY_RAHASIA'), 
+        'toleransi_detik' => 300, 
+    ], 
+ 
     'katalog' => [ 
         'per_halaman_bawaan' => 12, 
 
.env.example — perubahan B4 
 LAPAK_TAHAN_DANA_HARI=3 
 LAPAK_PENCAIRAN_MINIMUM=50000 
+# Hanya untuk mesin pengembangan. Di server sungguhan, ganti dengan rahasia dari gerbang. 
+LAPAK_GATEWAY_RAHASIA=rahasia-gerbang-untuk-latihan 
 
PERINGATAN — Rahasia gerbang setara kata sandi basis data 
Siapa pun yang memegang LAPAK_GATEWAY_RAHASIA dapat menandai tagihan mana pun lunas. Nilai pada 
.env.example hanya untuk mesin pengembangan dan boleh di-commit karena memang bukan rahasia. Di server 
sungguhan nilainya berasal dari dasbor gerbang pembayaran, disimpan di pengelola rahasia, tidak pernah masuk 
repositori, dan tidak pernah ditulis ke log. 
 
10.2 Layanan pembayaran, versi lengkap. 
app/Services/LayananPembayaran.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Domain\Pesanan\PelakuPesanan; 
use App\Domain\Pesanan\StatusPesanan; 
use App\Domain\Uang; 
use App\Enums\JenisDompet; 
use App\Enums\JenisMutasi; 
use App\Enums\StatusPembayaran; 
use App\Exceptions\GalatDomain; 
use App\Models\Checkout; 
use App\Models\Pembayaran; 
use Illuminate\Support\Str; 
  
/** 
 * Satu-satunya pintu perubahan tabel pembayaran. 
 * buat() dan tandaiKedaluwarsa() dipanggil dari dalam transaksi pemanggil; terima() membuka 
 * GembokCheckout sendiri. 
 */ 
final class LayananPembayaran 
{ 
    public function __construct( 
        private readonly GembokCheckout $gembok, 
        private readonly BukuBesar $buku, 
        private readonly MesinStatusPesanan $mesin, 
    ) {} 
  
    /** LP-7: tagihan sebesar total checkout, dengan batas bayar menurut LP-10. */ 
    public function buat(Checkout $checkout): Pembayaran 
    { 
        $pembayaran = new Pembayaran; 
        $pembayaran->forceFill([ 
            'checkout_id' => $checkout->id, 
            'kode' => 'BYR-'.Str::ulid(), 
            // Jumlahnya disalin dari checkout, tidak pernah dari klien (LP-6). 
            'jumlah_rupiah' => $checkout->total_rupiah, 
            'status' => StatusPembayaran::Menunggu, 

            'batas_bayar_pada' => now()->addHours(config('lapak.batas_bayar_jam')), 
        ])->save(); 
  
        return $pembayaran; 
    } 
  
    public function untuk(Checkout $checkout): Pembayaran 
    { 
        return Pembayaran::query()->where('checkout_id', $checkout->id)->firstOrFail(); 
    } 
  
    /** 
     * Dipanggil pembatalan otomatis, di dalam GembokCheckout. 
     * 
     * @return bool false bila tagihan sudah lunas, sudah kedaluwarsa, atau belum lewat batas 
     */ 
    public function tandaiKedaluwarsa(Checkout $checkout): bool 
    { 
        // Dibaca ulang dengan kunci: di antara penjadwal memilih tagihan ini dan gembok didapat, 
        // pembayarannya bisa saja sudah masuk. 
        $pembayaran = Pembayaran::query() 
            ->where('checkout_id', $checkout->id) 
            ->lockForUpdate() 
            ->firstOrFail(); 
  
        if ($pembayaran->status !== StatusPembayaran::Menunggu 
            || $pembayaran->batas_bayar_pada->isFuture()) { 
            return false; 
        } 
  
        $pembayaran->forceFill(['status' => StatusPembayaran::Kedaluwarsa])->save(); 
  
        return true; 
    } 
  
    /** 
     * Menerima kabar dari gerbang bahwa sebuah tagihan dibayar. Aman diterima berulang kali: 
     * gerbang pembayaran mengirim ulang webhook yang jawabannya tidak sampai kepadanya. 
     * 
     * @return string "dilunasi" bila kabar ini yang melunasi, "sudah_diproses" bila sudah lunas 
     */ 
    public function terima(string $kode, int $jumlahRupiah, string $idPeristiwa): string 
    { 
        // Dicari TANPA kunci, hanya untuk mengetahui checkout mana yang harus digembok. 
        $ditemukan = Pembayaran::query()->where('kode', $kode)->firstOrFail(); 
  
        return $this->gembok->dalam($ditemukan->checkout_id, function (Checkout $checkout) use ( 
            $ditemukan, $jumlahRupiah, $idPeristiwa, 
        ): string { 
            // Di bawah gembok, statusnya dibaca ulang dengan kunci. Dua webhook kembar antre di 
            // gembok; yang kedua mendapati tagihannya sudah lunas. 
            $pembayaran = Pembayaran::query() 
                ->whereKey($ditemukan->id) 
                ->lockForUpdate() 
                ->firstOrFail(); 
  
            if ($pembayaran->status === StatusPembayaran::Lunas) { 
                return 'sudah_diproses'; 
            } 
  
            if ($pembayaran->status === StatusPembayaran::Kedaluwarsa) { 
                throw new GalatDomain('LP-10', 'Tagihan ini sudah kedaluwarsa.', 409); 
            } 
  
            // Jumlah dibandingkan dengan tagihan di basis data. Kabar "sudah dibayar" untuk 
            // jumlah yang lebih kecil tidak melunasi apa pun (LP-6). 
            if ($pembayaran->jumlah_rupiah !== $jumlahRupiah) { 
                throw new GalatDomain( 
                    'JUMLAH_TIDAK_COCOK', 'Jumlah yang dibayar tidak sama dengan tagihan.', 422, 
                ); 
            } 
  
            $pembayaran->forceFill([ 
                'status' => StatusPembayaran::Lunas, 
                'lunas_pada' => now(), 
                'id_peristiwa' => $idPeristiwa, 
            ])->save(); 
  

            $this->tahanDana($checkout, $pembayaran); 
  
            return 'dilunasi'; 
        }); 
    } 
  
    /** LP-12: uang pembeli masuk escrow, satu pemindahan per pesanan. Pesanan ditandai dibayar. */ 
    private function tahanDana(Checkout $checkout, Pembayaran $pembayaran): void 
    { 
        $luar = $this->buku->dompetSistem(JenisDompet::Eksternal); 
        $escrow = $this->buku->dompetSistem(JenisDompet::Escrow); 
        $semua = $checkout->pesanan()->orderBy('id')->get(); 
  
        // Urutan kunci tim (docs/PETA-SKEMA.md): SELURUH pesanan dikunci dan ditandai lebih dulu, 
        // barulah dompet. Hanya SISTEM yang boleh menandai pesanan dibayar; tidak ada pintu 
        // pembeli untuk ini. 
        foreach ($semua as $pesanan) { 
            $this->mesin->pindahkan($pesanan, StatusPesanan::Dibayar, PelakuPesanan::Sistem); 
        } 
  
        foreach ($semua as $pesanan) { 
            $this->buku->pindahkan( 
                $luar, 
                $escrow, 
                Uang::dari($pesanan->total_rupiah), 
                JenisMutasi::DanaMasuk, 
                $pesanan->nomor, 
                "Pembayaran {$pembayaran->kode}", 
            ); 
        } 
    } 
} 
 
Ikuti metode terima() dari atas. 
1. Tagihan dicari tanpa kunci, hanya untuk mengetahui checkout mana yang harus digembok. Hasil pencarian 
ini tidak dipakai untuk memutuskan apa pun. 
2. Di bawah gembok, tagihan dibaca ulang dengan kunci. Dua webhook kembar antre di gembok. Yang kedua 
membaca status terkini, mendapati lunas, dan dijawab sudah_diproses. 
3. Tiga keputusan berurutan: sudah lunas, sudah kedaluwarsa, jumlah tidak cocok. Baru setelah ketiganya lewat, 
tagihan ditandai. 
4. tahanDana() menandai seluruh pesanan lebih dulu, baru memindahkan uang. Urutan itu mengikuti tangga 
kunci: pesanan di anak tangga kelima, dompet di anak tangga ketujuh. 
5. Setiap pemindahan merujuk nomor pesanan. Mutasi di buku besar dapat ditelusuri ke pesanannya, dan 
sebaliknya. 
Seluruhnya satu transaksi. Bila pesanan kedua tidak dapat ditandai dibayar, tanda lunas pada tagihan dan tanda 
dibayar pada pesanan pertama ikut batal. 
10.3 Pintu HTTP. 
app/Http/Controllers/WebhookPembayaranController.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Controllers; 
  
use App\Exceptions\GalatDomain; 
use App\Services\LayananPembayaran; 
use App\Services\TandaTanganGateway; 
use Illuminate\Http\JsonResponse; 
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Validator; 
  
/** 
 * POST /webhook/pembayaran: satu-satunya rute tulis yang publik. Pemanggilnya gerbang pembayaran. 
 * 

 * Tidak ada pintu "tandai lunas" bagi pembeli. Pesanan menjadi dibayar hanya lewat kabar 
 * bertanda tangan dari gerbang, dan jumlahnya dicocokkan dengan tagihan di basis data. 
 */ 
final class WebhookPembayaranController extends Controller 
{ 
    public function __invoke( 
        Request $request, 
        TandaTanganGateway $tandaTangan, 
        LayananPembayaran $pembayaran, 
    ): JsonResponse { 
        // Tanda tangan dihitung atas badan MENTAH, persis seperti yang diterima. Badan yang 
        // sudah diurai lalu disusun ulang bisa berbeda satu spasi dan tanda tangannya gagal. 
        $tanda = $request->header(TandaTanganGateway::HEADER); 
  
        if (! $tandaTangan->sah($tanda, $request->getContent())) { 
            // Satu jawaban untuk semua sebab: tanpa header, rahasia salah, badan diubah, atau basi. 
            throw new GalatDomain('TANDA_TANGAN_SALAH', 'Tanda tangan webhook tidak sah.', 401); 
        } 
  
        // Baru setelah pengirimnya terbukti, isinya dibaca. 
        $data = Validator::make($request->all(), [ 
            'id_peristiwa' => ['required', 'string', 'max:64'], 
            'kode_pembayaran' => ['required', 'string', 'max:30'], 
            'jumlah_rupiah' => ['required', 'integer', 'min:1'], 
        ])->validate(); 
  
        $hasil = $pembayaran->terima( 
            $data['kode_pembayaran'], $data['jumlah_rupiah'], $data['id_peristiwa'], 
        ); 
  
        return $this->sukses(['hasil' => $hasil]); 
    } 
} 
 
Controller ini tidak memakai FormRequest, dan itu disengaja. FormRequest memvalidasi isi sebelum controller 
berjalan. Di sini urutannya harus dibalik: pengirim dibuktikan dulu dari badan mentah, barulah isinya diurai dan 
divalidasi. Orang yang tidak memegang rahasia tidak boleh tahu kolom apa yang wajib ada. 
app/Http/Controllers/PembayaranSayaController.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Controllers; 
  
use App\Http\Resources\PembayaranResource; 
use App\Models\Pembayaran; 
use Illuminate\Database\Eloquent\Builder; 
use Illuminate\Http\JsonResponse; 
use Illuminate\Http\Request; 
  
final class PembayaranSayaController extends Controller 
{ 
    /** 
     * GET /pembayaran/{kode}: tagihan milik pembeli yang sedang masuk. Hanya MEMBACA. 
     * Kode tagihan orang lain dijawab 404. 
     */ 
    public function show(Request $request, string $kode): JsonResponse 
    { 
        $pembayaran = Pembayaran::query() 
            ->where('kode', $kode) 
            ->whereHas('checkout', fn (Builder $c) => $c->where('pembeli_id', $request->user()->id)) 
            ->with('checkout.pesanan') 
            ->firstOrFail(); 
  
        return $this->sukses([ 
            'pembayaran' => PembayaranResource::make($pembayaran), 
            'pesanan' => $pembayaran->checkout->pesanan->pluck('nomor')->all(), 
        ]); 
    } 
} 
 

routes/api/dompet.php — perubahan B4 
 use App\Http\Controllers\BukuBesarController; 
 use App\Http\Controllers\DompetController; 
+use App\Http\Controllers\PembayaranSayaController; 
+use App\Http\Controllers\WebhookPembayaranController; 
 use Illuminate\Support\Facades\Route; 
  
   ... 
         Route::get('dompet/{id}/mutasi', [BukuBesarController::class, 'mutasi'])->name('dompet.mutasi'); 
     }); 
+ 
+// Tagihan milik pembeli yang sedang masuk. Pembeli hanya dapat MELIHAT tagihannya. 
+Route::get('pembayaran/{kode}', [PembayaranSayaController::class, 'show']) 
+    ->middleware(['auth:sesi', 'izin:pesanan.buat']) 
+    ->name('pembayaran.show'); 
+ 
+// Satu-satunya rute tulis tanpa kunci sesi. Pemanggilnya gerbang pembayaran, dan yang 
+// menjaganya adalah tanda tangan HMAC di dalam controller. Terdaftar pada RUTE_PUBLIK. 
+Route::post('webhook/pembayaran', WebhookPembayaranController::class)->name('webhook.pembayaran'); 
 
tests/Feature/Sistem/RuteTerlindungTest.php — perubahan B4 
     'GET api/v1/toko/{slug}', 
     'POST api/v1/simulasi-belanja', 
+    // Dipanggil gerbang pembayaran, bukan pengguna. Dijaga tanda tangan HMAC, bukan kunci sesi. 
+    'POST api/v1/webhook/pembayaran', 
 ]; 
  
 
Daftar RUTE_PUBLIK bertambah untuk pertama kalinya sejak Sesi 2. Uji itu milik Sekar, sehingga penambahan satu 
rute publik otomatis meminta tinjauan pemilik otorisasi. Begitulah seharusnya: rute tulis tanpa kunci sesi adalah 
keputusan yang perlu dilihat dua orang. 
tests/Feature/Sistem/matriks/dompet.php — perubahan B4 
     'GET api/v1/buku-besar/dompet' => ['keuangan', 'auditor'], 
     'GET api/v1/buku-besar/dompet/{id}/mutasi' => ['keuangan', 'auditor'], 
+ 
+    'GET api/v1/pembayaran/{kode}' => ['pembeli'], 
 ]; 
 
10.4 Invarian kelima dan simulasi gerbang. 
app/Console/Commands/PeriksaBuku.php — perubahan B4 
 namespace App\Console\Commands; 
  
+use App\Domain\Pesanan\StatusPesanan; 
 use App\Enums\JenisDompet; 
 use App\Models\Dompet; 
 use App\Models\MutasiSaldo; 
+use App\Models\Pesanan; 
 use Illuminate\Console\Command; 
  
   ... 
     protected $signature = 'lapak:periksa-buku'; 
  
-    protected $description = 'Memeriksa empat invarian buku besar (LP-13); kode keluar 1 bila dilanggar.'; 
+    protected $description = 'Memeriksa lima invarian buku besar; kode keluar 1 bila dilanggar.'; 
  
     public function handle(): int 
   ... 
         } 
  
+        // 5. Dana yang ditahan sama dengan nilai pesanan yang sudah dibayar dan belum selesai. 
+        //    Invarian ini mengikat buku besar ke tabel pesanan: uang di escrow selalu dapat 
+        //    ditunjuk pesanannya. 
+        $menahanDana = [StatusPesanan::Dibayar, StatusPesanan::Dikemas, StatusPesanan::Dikirim]; 
+        $ditahan = (int) Pesanan::query()->whereIn('status', $menahanDana)->sum('total_rupiah'); 
+        $escrow = (int) Dompet::query() 
+            ->where('kode', JenisDompet::Escrow->value) 

+            ->value('saldo_rupiah'); 
+ 
+        if ($escrow !== $ditahan) { 
+            $pelanggaran[] = "Saldo escrow {$escrow}, tetapi dana pesanan yang ditahan {$ditahan}."; 
+        } 
+ 
         $this->table(['Dompet', 'Jenis', 'Saldo (Rp)'], $baris); 
  
 
Empat invarian dari Sesi 1 memeriksa buku besar terhadap dirinya sendiri. Invarian kelima memeriksanya terhadap 
tabel lain: saldo escrow harus sama dengan nilai pesanan yang sedang menahan dana. Bila seseorang mengubah 
status pesanan lewat jalan belakang, atau memindahkan uang tanpa mengubah status, perintah ini keluar dengan 
kode 1. 
lapak:simulasi-bayar berperan sebagai gerbang: ia menyusun kabar pelunasan, menandatanganinya dengan 
TandaTanganGateway::buat(), lalu menyerahkannya ke kernel HTTP seperti PanggilHttp. Opsi --jumlah, --
ganda, dan --rahasia dipakai untuk mencoba jumlah yang salah, kabar kembar, dan pemalsuan. Perintah itu 
menolak berjalan di produksi; berkasnya ada pada berkas pendamping. 
10.5 Uji. 
tests/Feature/Dompet/WebhookPembayaranTest.php — keadaan awal, pelunasan, dan kabar kembar 
<?php 
  
use App\Domain\Pesanan\StatusPesanan; 
use App\Enums\JenisDompet; 
use App\Enums\StatusPembayaran; 
use App\Models\Checkout; 
use App\Models\Dompet; 
use App\Models\MutasiSaldo; 
use App\Models\Pembayaran; 
use App\Models\Pesanan; 
use App\Services\TandaTanganGateway; 
  
/* 
| Keadaan awal: satu checkout Rp 489.500 berisi dua pesanan (382.000 dan 107.500) yang 
| menunggu pembayaran. 
*/ 
beforeEach(function () { 
    config(['lapak.gateway.rahasia' => 'rahasia-uji', 'lapak.gateway.toleransi_detik' => 300]); 
  
    $this->checkout = Checkout::factory()->create(['total_rupiah' => 489_500]); 
    $this->tagihan = Pembayaran::factory()->for($this->checkout) 
        ->create(['jumlah_rupiah' => 489_500]); 
    $this->batik = Pesanan::factory()->for($this->checkout)->create(['total_rupiah' => 382_000]); 
    $this->oleh = Pesanan::factory()->for($this->checkout)->create(['total_rupiah' => 107_500]); 
  
    $this->kabar = fn (array $timpa = []) => $timpa + [ 
        'id_peristiwa' => 'evt_01', 
        'kode_pembayaran' => $this->tagihan->kode, 
        'jumlah_rupiah' => 489_500, 
    ]; 
  
    // Mengirim webhook seperti gerbang: badan mentah ditandatangani, lalu dikirim apa adanya. 
    $this->gerbang = function (array $kabar, ?string $tanda = null) { 
        $badan = json_encode($kabar); 
        $tanda ??= app(TandaTanganGateway::class)->buat($badan); 
  
        return $this->call('POST', '/api/v1/webhook/pembayaran', [], [], [], [ 
            'CONTENT_TYPE' => 'application/json', 
            'HTTP_X_GATEWAY_SIGNATURE' => $tanda, 
        ], $badan); 
    }; 
  
    $this->saldo = fn (JenisDompet $jenis) => (int) Dompet::query() 
        ->where('kode', $jenis->value)->value('saldo_rupiah'); 
}); 
  
it('melunasi tagihan, menahan dana di escrow, dan menandai pesanan dibayar (LP-12)', function () { 
    ($this->gerbang)(($this->kabar)()) 
        ->assertOk() 

        ->assertJsonPath('data.hasil', 'dilunasi'); 
  
    expect($this->tagihan->refresh()) 
        ->status->toBe(StatusPembayaran::Lunas) 
        ->id_peristiwa->toBe('evt_01') 
        ->lunas_pada->not->toBeNull() 
        ->and($this->batik->refresh()->status)->toBe(StatusPesanan::Dibayar) 
        ->and($this->oleh->refresh()->status)->toBe(StatusPesanan::Dibayar) 
        ->and(($this->saldo)(JenisDompet::Escrow))->toBe(489_500) 
        ->and(($this->saldo)(JenisDompet::Eksternal))->toBe(-489_500); 
  
    // Satu pemindahan per pesanan, dirujuk dengan nomor pesanannya. 
    $mutasiBatik = MutasiSaldo::query()->where('rujukan', $this->batik->nomor); 
  
    expect((int) (clone $mutasiBatik)->sum('jumlah_rupiah'))->toBe(0) 
        ->and((clone $mutasiBatik)->count())->toBe(2) 
        ->and(MutasiSaldo::count())->toBe(4); 
  
    $this->artisan('lapak:periksa-buku')->assertSuccessful(); 
}); 
  
it('menerima kabar kembar tanpa membukukan uangnya dua kali', function () { 
    ($this->gerbang)(($this->kabar)())->assertOk()->assertJsonPath('data.hasil', 'dilunasi'); 
    ($this->gerbang)(($this->kabar)())->assertOk()->assertJsonPath('data.hasil', 'sudah_diproses'); 
    // Gerbang mengulang dengan pengenal peristiwa baru: tetap tidak berefek. 
    ($this->gerbang)(($this->kabar)(['id_peristiwa' => 'evt_02'])) 
        ->assertOk() 
        ->assertJsonPath('data.hasil', 'sudah_diproses'); 
  
    expect(($this->saldo)(JenisDompet::Escrow))->toBe(489_500) 
        ->and(MutasiSaldo::count())->toBe(4) 
        ->and($this->tagihan->refresh()->id_peristiwa)->toBe('evt_01'); 
}); 
 
tests/Feature/Dompet/WebhookPembayaranTest.php — enam tanda tangan yang tidak sah, dan rahasia kosong 
it('menolak webhook yang tanda tangannya tidak sah dengan satu jawaban yang sama', function ( 
    Closure $tanda, 
) { 
    $badan = json_encode(($this->kabar)()); 
  
    ($this->gerbang)(($this->kabar)(), $tanda($badan)) 
        ->assertUnauthorized() 
        ->assertExactJson(['sukses' => false, 'galat' => [ 
            'kode' => 'TANDA_TANGAN_SALAH', 
            'pesan' => 'Tanda tangan webhook tidak sah.', 
            'rincian' => null, 
        ]]); 
  
    expect($this->tagihan->refresh()->status)->toBe(StatusPembayaran::Menunggu) 
        ->and(MutasiSaldo::count())->toBe(0); 
})->with([ 
    'tanpa header' => [fn (string $badan) => ''], 
    'rahasia salah' => [ 
        fn (string $badan) => app(TandaTanganGateway::class)->buat($badan, rahasia: 'tebakan'), 
    ], 
    'ditandatangani untuk badan lain' => [ 
        fn (string $badan) => app(TandaTanganGateway::class) 
            ->buat(str_replace('489500', '1', $badan)), 
    ], 
    'rekaman lama diputar ulang' => [ 
        fn (string $badan) => app(TandaTanganGateway::class) 
            ->buat($badan, now()->subMinutes(6)->getTimestamp()), 
    ], 
    'waktu dari masa depan' => [ 
        fn (string $badan) => app(TandaTanganGateway::class) 
            ->buat($badan, now()->addMinutes(6)->getTimestamp()), 
    ], 
    'bentuk header salah' => [fn (string $badan) => 'v1='.hash('sha256', $badan)], 
]); 
  
it('menolak semua webhook bila rahasia belum diisi', function () { 
    config(['lapak.gateway.rahasia' => null]); 
    $badan = json_encode(($this->kabar)()); 
  
    // Penyerang menandatangani dengan rahasia kosong, berharap server juga memakainya. 
    $palsu = 't='.time().',v1='.hash_hmac('sha256', time().'.'.$badan, ''); 

    ($this->gerbang)(($this->kabar)(), $palsu)->assertUnauthorized(); 
  
    expect($this->tagihan->refresh()->status)->toBe(StatusPembayaran::Menunggu); 
}); 
 
tests/Feature/Dompet/WebhookPembayaranTest.php — pelunasan batal seluruhnya, dan tidak ada pintu bagi pembeli 
it('membatalkan seluruh pelunasan bila satu pesanan tidak dapat ditandai dibayar', function () { 
    // Keadaan yang seharusnya tidak pernah terjadi: satu pesanan sudah batal, tagihannya menunggu. 
    $this->oleh->forceFill(['status' => StatusPesanan::Dibatalkan])->save(); 
  
    ($this->gerbang)(($this->kabar)())->assertConflict()->assertJsonPath('galat.kode', 'LP-10'); 
  
    // Tagihan dan pesanan pertama sempat ditandai, lalu semuanya dibatalkan bersama. 
    expect($this->tagihan->refresh()->status)->toBe(StatusPembayaran::Menunggu) 
        ->and($this->batik->refresh()->status)->toBe(StatusPesanan::MenungguPembayaran) 
        ->and(MutasiSaldo::count())->toBe(0); 
}); 
  
it('tidak menyediakan pintu apa pun bagi pembeli untuk menandai tagihannya lunas', function () { 
    $pembeli = $this->checkout->pembeli; 
    $this->sebagai($pembeli); 
  
    $this->getJson("/api/v1/pembayaran/{$this->tagihan->kode}") 
        ->assertOk() 
        ->assertJsonPath('data.pembayaran.status', 'menunggu') 
        ->assertJsonPath('data.pesanan', [$this->batik->nomor, $this->oleh->nomor]); 
  
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $metode) { 
        $this->json($metode, "/api/v1/pembayaran/{$this->tagihan->kode}", ['status' => 'lunas']) 
            ->assertStatus(405); 
    } 
  
    // Pembeli yang sah pun tidak dapat memanggil webhook tanpa rahasia gerbang. 
    $this->postJson('/api/v1/webhook/pembayaran', ($this->kabar)())->assertUnauthorized(); 
    expect($this->tagihan->refresh()->status)->toBe(StatusPembayaran::Menunggu); 
}); 
 
Keenam tanda tangan tidak sah menerima jawaban yang persis sama, dan uji memeriksanya dengan 
assertExactJson. Uji "rahasia belum diisi" menirukan penyerang yang paling cerdik: ia menandatangani dengan 
rahasia kosong, berharap server yang lupa dikonfigurasi juga memakainya. 
10.6 Skenario balapan. 
app/Balapan/WebhookGanda.php — yang dijalankan tiap proses dan yang diperiksa 
    public function jalankan(array $konteks, int $nomor): string 
    { 
        [$status, $json] = $this->panggil( 
            'POST', 
            '/api/v1/webhook/pembayaran', 
            json_decode($konteks['badan'], true), 
            [TandaTanganGateway::HEADER => $konteks['tanda']], 
        ); 
  
        return $status === 200 ? "200 {$json['data']['hasil']}" : $this->ringkas($status, $json); 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $checkout = Checkout::query()->findOrFail($konteks['checkout']); 
        $escrow = app(BukuBesar::class)->dompetSistem(JenisDompet::Escrow)->saldo_rupiah; 
        $masuk = $escrow - $konteks['escrow_awal']; 
        $nomor = $checkout->pesanan()->pluck('nomor'); 
        $mutasi = MutasiSaldo::query()->whereIn('rujukan', $nomor)->count(); 
        $melunasi = count(array_filter($hasil, fn (string $h) => $h === '200 dilunasi')); 
  
        $pelanggaran = []; 
        if ($melunasi !== 1) { 
            $pelanggaran[] = "{$melunasi} kabar dianggap melunasi, seharusnya tepat 1."; 
        } 
        if ($masuk !== self::TAGIHAN) { 

            $pelanggaran[] = "Escrow bertambah {$masuk}, padahal tagihannya ".self::TAGIHAN.'.'; 
        } 
        if ($mutasi !== 4) { 
            $pelanggaran[] = "Tercatat {$mutasi} baris mutasi untuk dua pesanan, seharusnya 4."; 
        } 
        if ($checkout->pesanan()->where('status', StatusPesanan::Dibayar)->count() !== 2) { 
            $pelanggaran[] = 'Tidak semua pesanan berstatus dibayar.'; 
        } 
        $tagihan = Pembayaran::query()->where('checkout_id', $checkout->id)->sole(); 
        if ($tagihan->status !== StatusPembayaran::Lunas) { 
            $pelanggaran[] = 'Tagihan tidak berstatus lunas.'; 
        } 
  
        return $pelanggaran; 
    } 
 
Keluaran — php artisan lapak:balapan webhook-ganda 
webhook-ganda: Kabar pembayaran yang sama tiba serentak: dana masuk escrow tepat satu kali 
+--------------------+---------------+ 
| Hasil              | Jumlah proses | 
+--------------------+---------------+ 
| 200 dilunasi       | 1             | 
| 200 sudah_diproses | 5             | 
+--------------------+---------------+ 
LULUS: 6 proses serentak, tidak ada aturan yang dilanggar. 
 
docs/KONTRAK-API.md — bagian yang ditambahkan 
## Webhook pembayaran 
  
- `POST /webhook/pembayaran` adalah satu-satunya rute tulis tanpa kunci sesi. Pemanggilnya 
  gerbang pembayaran. Pembeli tidak memiliki pintu apa pun untuk menandai tagihannya lunas. 
- Gerbang mengirim header `X-Gateway-Signature: t=<detik unix>,v1=<tanda>`, dengan `<tanda>` 
  berupa HMAC-SHA256 atas teks `<t>.<badan mentah>` memakai rahasia bersama `LAPAK_GATEWAY_RAHASIA`. 
- Tanpa header, rahasia salah, badan diubah, atau selisih waktu lebih dari 5 menit: jawabannya 
  selalu 401 `TANDA_TANGAN_SALAH` yang sama. Rahasia yang kosong menolak seluruh webhook. 
- Badan: `id_peristiwa`, `kode_pembayaran`, `jumlah_rupiah`. Jumlah dicocokkan dengan tagihan di 
  basis data; selisih satu rupiah pun dijawab 422 `JUMLAH_TIDAK_COCOK` dan tidak melunasi apa pun. 
- Kabar yang sama boleh tiba berkali-kali. Yang pertama dijawab `"hasil": "dilunasi"`, sisanya 
  `"hasil": "sudah_diproses"`, dan uangnya dibukukan satu kali. 
 
Terminal — Dimas 
./vendor/bin/pint 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git add app/Services/TandaTanganGateway.php config/lapak.php .env.example 
git commit -m "feat: tanda tangan gateway dengan HMAC dan batas waktu" 
git add app/Services/LayananPembayaran.php app/Http/Controllers/WebhookPembayaranController.php \ 
        app/Http/Controllers/PembayaranSayaController.php routes/api/dompet.php \ 
        tests/Feature/Sistem docs/KONTRAK-API.md 
git commit -m "feat: webhook pembayaran menahan dana di escrow tepat satu kali (LP-12, LP-13)" 
git add app/Console/Commands/SimulasiBayar.php app/Console/Commands/PeriksaBuku.php 
git commit -m "feat: perintah lapak:simulasi-bayar dan invarian dana tertahan" 
git add tests/Feature/Dompet/WebhookPembayaranTest.php app/Balapan/WebhookGanda.php 
git commit -m "test: uji webhook pembayaran dan skenario webhook-ganda" 
git push -u origin sesi-4/webhook-dimas 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 340 uji lulus. Jangan lupa menambahkan LAPAK_GATEWAY_RAHASIA ke .env Anda 
sendiri; tanpa itu seluruh webhook di mesin Anda dijawab 401, dan itu memang perilaku yang benar. 
Langkah 11 — B5: Klasifikasi Rute POST, Skenario Lintas Domain, dan CI MySQL (Sekar) 
Cabang: sesi-4/balapan-ci-sekar. Empat PR lain masing-masing menjaga satu aturan. PR ini menjaga agar 
keempatnya tetap terjaga besok. 
11.1 Setiap rute POST harus diputuskan. 

tests/Feature/Sistem/RuteIdempotenTest.php 
<?php 
  
use Illuminate\Support\Facades\Route; 
  
/* 
| LP-11, ditegakkan dengan cara yang sama seperti RUTE_PUBLIK dan matriks otorisasi. 
| 
| Setiap rute POST di bawah /api/v1 WAJIB tercatat pada salah satu dari dua daftar ini. POST 
| adalah satu-satunya metode yang tidak idempoten menurut HTTP: mengulanginya dapat berefek dua 
| kali. Menambah rute POST karenanya menuntut satu keputusan tertulis: dijaga Idempotency-Key, 
| atau aman diulang karena sebab yang disebutkan. 
*/ 
const POST_BERKUNCI = [ 
    'POST api/v1/checkout', 
    'POST api/v1/toko-saya/produk/{id}/stok', 
]; 
  
const POST_TANPA_KUNCI = [ 
    'POST api/v1/registrasi' => 'Surel unik: pengulangan dijawab 422, tidak membuat akun kedua.', 
    'POST api/v1/masuk' => 'Pengulangan hanya menerbitkan sesi tambahan yang dapat dicabut.', 
    'POST api/v1/keluar' => 'Mencabut sesi yang sudah dicabut tidak mengubah apa pun.', 
    'POST api/v1/sesi/konfirmasi' => 'Kunci lama mati setelah berhasil; pengulangan dijawab 401.', 
    'POST api/v1/simulasi-belanja' => 'Hanya menghitung, tidak menulis.', 
    'POST api/v1/toko-saya' => 'Satu pengguna satu toko (LP-3): pengulangan dijawab 409.', 
    'POST api/v1/toko-saya/produk' => 'Tidak menyentuh uang; produk kembar dapat diarsipkan.', 
    'POST api/v1/admin/toko/{id}/setujui' => 'UPDATE bersyarat: pengulangan dijawab 409.', 
    'POST api/v1/admin/toko/{id}/tangguhkan' => 'UPDATE bersyarat: pengulangan dijawab 409.', 
    'POST api/v1/alamat' => 'Tidak menyentuh uang maupun stok; jumlah alamat dibatasi.', 
    'POST api/v1/webhook/pembayaran' => 'Dijaga status tagihan di bawah gembok checkout.', 
]; 
  
/** @return array<string, list<string>> "POST alamat" => middleware rute itu */ 
function rutePost(): array 
{ 
    $daftar = []; 
  
    foreach (Route::getRoutes() as $rute) { 
        if (str_starts_with($rute->uri(), 'api/v1') && in_array('POST', $rute->methods(), true)) { 
            $daftar['POST '.$rute->uri()] = array_values( 
                array_filter($rute->gatherMiddleware(), 'is_string'), 
            ); 
        } 
    } 
  
    return $daftar; 
} 
  
it('mencatat setiap rute POST pada tepat satu daftar', function () { 
    $tercatat = [...POST_BERKUNCI, ...array_keys(POST_TANPA_KUNCI)]; 
    $rute = array_keys(rutePost()); 
  
    $belum = array_values(array_diff($rute, $tercatat)); 
    $usang = array_values(array_diff($tercatat, $rute)); 
    $ganda = array_values(array_intersect(POST_BERKUNCI, array_keys(POST_TANPA_KUNCI))); 
  
    expect($belum)->toBeEmpty('Rute POST belum diklasifikasi: '.implode(', ', $belum)) 
        ->and($usang)->toBeEmpty('Tercatat tetapi rutenya tidak ada: '.implode(', ', $usang)) 
        ->and($ganda)->toBeEmpty('Tercatat pada kedua daftar: '.implode(', ', $ganda)); 
}); 
  
it('memasang idempoten pada setiap rute berkunci, di belakang kunci sesi', function () { 
    $salah = []; 
  
    foreach (POST_BERKUNCI as $nama) { 
        $middleware = rutePost()[$nama] ?? []; 
        $auth = array_search('auth:sesi', $middleware, true); 
        $idempoten = array_search('idempoten', $middleware, true); 
  
        // Idempoten mencatat kunci atas nama pengguna, maka harus berjalan setelah auth:sesi. 
        if ($auth === false || $idempoten === false || $idempoten < $auth) { 
            $salah[] = $nama; 
        } 
    } 
  
    expect($salah)->toBeEmpty('Tanpa idempoten atau urutannya salah: '.implode(', ', $salah)); 
}); 
  

it('tidak memasang idempoten pada rute yang dinyatakan tidak memerlukannya', function () { 
    $salah = array_keys(array_filter( 
        array_intersect_key(rutePost(), POST_TANPA_KUNCI), 
        fn (array $middleware) => in_array('idempoten', $middleware, true), 
    )); 
  
    expect($salah)->toBeEmpty('Tanpa kunci tetapi memasang idempoten: '.implode(', ', $salah)); 
}); 
  
it('menuliskan alasan untuk setiap rute tanpa kunci', function () { 
    foreach (POST_TANPA_KUNCI as $nama => $alasan) { 
        expect(mb_strlen($alasan))->toBeGreaterThan(20, "Alasan {$nama} terlalu singkat."); 
    } 
}); 
 
Uji ini bekerja seperti RUTE_PUBLIK pada Sesi 2 dan matriks otorisasi pada Sesi 3: daftar yang ditulis tangan 
dibandingkan dengan rute yang benar-benar terdaftar. Menambah rute POST tanpa menyentuh berkas ini membuat 
CI merah dan menyebut nama rutenya. Penulisnya dipaksa menjawab satu pertanyaan secara tertulis: apa yang 
terjadi bila permintaan ini terkirim dua kali? 
Sebelas alasan pada daftar kedua layak dibaca satu per satu. Sebagian menyebut mekanisme yang membuat 
pengulangan tidak berefek: surel unik, UPDATE bersyarat, status di bawah gembok, atau tidak adanya penulisan 
sama sekali. Sebagian lain mengakui pengulangannya berefek tetapi murah dibereskan: sesi tambahan dapat 
dicabut, produk kembar dapat diarsipkan. Keduanya sah, selama tertulis dan disetujui peninjau. Yang tidak sah 
adalah rute yang tidak pernah dipikirkan. 
11.2 Tiga skenario lintas domain. Skenario milik tiap domain memanggil service-nya langsung atau satu pintunya 
sendiri. Tiga skenario berikut sengaja melintasi batas domain. 
app/Balapan/KunciGanda.php — yang dijalankan tiap proses dan yang diperiksa 
    public function jalankan(array $konteks, int $nomor): string 
    { 
        $badan = ['alamat_id' => $konteks['alamat']]; 
  
        [$status, $json, $header] = $this->panggil('POST', '/api/v1/checkout', $badan, [ 
            'Authorization' => 'Bearer '.$konteks['kunci_sesi'], 
            'Idempotency-Key' => $konteks['kunci_idempotensi'], 
        ]); 
  
        if ($status !== 201) { 
            return $this->ringkas($status, $json); 
        } 
  
        $jenis = isset($header['idempotency-replayed']) ? 'ulangan' : 'pertama'; 
  
        // Kode checkout ikut dicatat: semua proses harus menerima checkout YANG SAMA. 
        return "201 {$jenis} ".substr($json['data']['kode'], -6); 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $checkout = Checkout::query()->where('pembeli_id', $konteks['pembeli'])->count(); 
        $stok = Produk::query()->findOrFail($konteks['produk'])->stok; 
        $pertama = count(array_filter( 
            $hasil, 
            fn (string $h) => str_starts_with($h, '201 pertama'), 
        )); 
        $kode = array_unique(array_map(fn (string $h) => substr($h, -6), $hasil)); 
  
        $pelanggaran = []; 
        if ($checkout !== 1) { 
            $pelanggaran[] = "Lahir {$checkout} checkout dari satu Idempotency-Key, seharusnya 1."; 
        } 
        if ($pertama !== 1) { 
            $pelanggaran[] = "{$pertama} proses merasa menjadi yang pertama, seharusnya tepat 1."; 
        } 
        if (count($kode) !== 1) { 
            $pelanggaran[] = 'Proses yang berbeda menerima jawaban yang berbeda.'; 
        } 
        if ($stok !== 48) { 

            $pelanggaran[] = 'Stok berkurang '.(50 - $stok).', padahal keranjang hanya berisi 2.'; 
        } 
  
        return $pelanggaran; 
    } 
 
KunciGanda menguji indeks unik pada Langkah 2 dengan cara yang tidak dapat dilakukan Pest: enam permintaan 
dengan kunci yang sama tiba bersamaan, sebelum satu pun selesai. Tepat satu proses harus merasa menjadi yang 
pertama, dan keenamnya harus menerima kode checkout yang sama. 
RebutanCheckout adalah rebutan-stok yang dijalankan lewat seluruh lapisan: dua belas pembeli berbeda, 
masing-masing dengan sesi, keranjang, alamat, dan kunci idempotensinya sendiri. Yang diperiksa bukan hanya stok. 
Jumlah checkout, pesanan, dan tagihan harus sama dengan jumlah jawaban 201, sehingga tidak ada checkout 
setengah jadi milik pembeli yang ditolak. 
app/Balapan/BatalSaatCheckout.php — isi kelas tanpa keterangan dan jumlah proses 
    public function siapkan(): array 
    { 
        $akhiran = Str::lower(Str::random(8)); 
        $toko = Toko::factory()->create(['slug' => "toko-{$akhiran}"]); 
        $kategori = Kategori::query()->firstOrFail(); 
  
        [$kecil, $besar] = Produk::factory()->count(2)->for($toko)->for($kategori) 
            ->sequence(['slug' => "produk-a-{$akhiran}"], ['slug' => "produk-b-{$akhiran}"]) 
            ->create(['stok' => self::STOK_AWAL]) 
            ->all(); 
  
        $checkout = []; 
        foreach (range(1, intdiv($this->proses(), 2)) as $ke) { 
            $induk = Checkout::factory()->create(); 
            $pesanan = Pesanan::factory()->for($induk)->for($toko)->create(); 
            // Nomor produk besar disimpan lebih dulu. 
            ButirPesanan::factory()->for($pesanan)->create(['produk_id' => $besar->id]); 
            ButirPesanan::factory()->for($pesanan)->create(['produk_id' => $kecil->id]); 
            Pembayaran::factory()->for($induk)->create(['batas_bayar_pada' => now()->subMinute()]); 
            $checkout[] = $induk->id; 
        } 
  
        return ['produk' => [$kecil->id, $besar->id], 'checkout' => $checkout]; 
    } 
  
    public function jalankan(array $konteks, int $nomor): string 
    { 
        if ($nomor % 2 === 0) { 
            $id = $konteks['checkout'][intdiv($nomor, 2) - 1]; 
  
            return $this->pembatalan->batalkan($id) ? 'penjadwal: batal' : 'penjadwal: lewati'; 
        } 
  
        try { 
            DB::transaction(function () use ($konteks): void { 
                foreach ($this->penjaga->ambil($konteks['produk']) as $produk) { 
                    $this->penjaga->kurangi($produk, 1); 
                } 
            }); 
        } catch (ProdukTidakDapatDibeli) { 
            return 'pembeli: ditolak'; 
        } 
  
        return 'pembeli: beli 2'; 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $batal = count(array_filter($hasil, fn (string $h) => $h === 'penjadwal: batal')); 
        $beli = count(array_filter($hasil, fn (string $h) => $h === 'pembeli: beli 2')); 
        $seharusnya = self::STOK_AWAL + $batal - $beli; 
  
        $pelanggaran = []; 
        if ($batal + $beli !== count($hasil)) { 
            $pelanggaran[] = 'Ada proses yang gagal, misalnya karena kebuntuan (deadlock).'; 
        } 

        foreach (Produk::query()->findMany($konteks['produk']) as $produk) { 
            if ($produk->stok !== $seharusnya) { 
                $pelanggaran[] = "Stok produk {$produk->id} adalah {$produk->stok}, " 
                    ."seharusnya {$seharusnya}."; 
            } 
        } 
  
        return $pelanggaran; 
    } 
 
Skenario ketiga menjawab bantahan Sekar sendiri pada Langkah 1: siapa yang menjaga urutan kunci? Ia 
mempertemukan dua jalur yang sama-sama mengunci produk, yaitu pembelian dan pembatalan otomatis, lalu 
sengaja menyimpan butir pesanan dengan nomor produk besar lebih dulu. Bila kedua jalur mengunci dengan 
urutan yang sama, tidak ada yang terjadi. Bila tidak, MySQL mematikan salah satu proses. 
11.3 Tugas CI kedua. 
.github/workflows/ci.yml — tugas yang ditambahkan 
         run: ./vendor/bin/pest --ci 
+ 
+  # Tugas kedua, sejak Sesi 4. SQLite tidak mengenal SELECT ... FOR UPDATE dan hanya mengizinkan 
+  # satu penulis, sehingga meloloskan kode yang salah di bawah beban. Tugas ini mengulang seluruh 
+  # pengujian pada MySQL, lalu melepas proses serentak sungguhan lewat lapak:balapan. 
+  kualitas-mysql: 
+    name: kualitas-mysql 
+    runs-on: ubuntu-latest 
+    services: 
+      mysql: 
+        image: mysql:8.0 
+        env: 
+          MYSQL_DATABASE: lapak 
+          MYSQL_ROOT_PASSWORD: rahasia-ci 
+        ports: 
+          - 3306:3306 
+        options: >- 
+          --health-cmd="mysqladmin ping -h 127.0.0.1 -prahasia-ci" 
+          --health-interval=10s 
+          --health-timeout=5s 
+          --health-retries=10 
+    env: 
+      DB_CONNECTION: mysql 
+      DB_HOST: 127.0.0.1 
+      DB_PORT: 3306 
+      DB_DATABASE: lapak 
+      DB_USERNAME: root 
+      DB_PASSWORD: rahasia-ci 
+    steps: 
+      - uses: actions/checkout@v4 
+ 
+      - uses: shivammathur/setup-php@v2 
+        with: 
+          php-version: '8.4' 
+          extensions: mbstring, intl, pdo_mysql 
+          coverage: none 
+ 
+      - name: Pasang dependensi 
+        run: composer install --no-interaction --no-progress --prefer-dist 
+ 
+      - name: Siapkan lingkungan 
+        run: | 
+          cp .env.example .env 
+          php artisan key:generate 
+ 
+      - name: Pengujian pada MySQL 
+        run: ./vendor/bin/pest --ci 
+ 
+      # Pengujian di atas mengosongkan basis data, maka data acuan diisi ulang lebih dulu. 
+      - name: Migrasi dan seeder dari basis data kosong 
+        run: php artisan migrate:fresh --seed --force 
+ 
+      - name: Uji balapan 
+        run: php artisan lapak:balapan --semua 
+ 
+      - name: Buku besar seimbang setelah balapan 
+        run: php artisan lapak:periksa-buku 

+ 
 
Tugas kualitas tidak berubah dan tetap menjadi umpan balik cepat. Tugas kualitas-mysql menyalakan MySQL 
8.0 sebagai layanan, menjalankan seluruh uji Pest padanya, mengisi ulang data acuan, lalu menjalankan seluruh 
skenario balapan dan memeriksa buku besar sesudahnya. lapak:balapan --semua keluar dengan kode 1 bila satu 
skenario saja melaporkan pelanggaran. 
CATATAN — Berkas alur kerja ini belum dijalankan di GitHub Actions 
Susunannya mengikuti dokumentasi GitHub tentang kontainer layanan, dan setiap perintah di dalamnya sudah 
dijalankan pada MySQL 8.0 lokal dengan hasil seperti pada dokumen ini. Yang belum terbukti adalah perilakunya di 
mesin GitHub: waktu MySQL siap menerima sambungan, dan apakah mesin itu cukup cepat bagi skenario serempak. 
Bila skenario itu melaporkan proses yang terlambat, naikkan jeda garis mulai pada Balapan.php, jangan 
melonggarkan aturan skenario lain. 
 
Terminal — Sekar 
./vendor/bin/pint 
./vendor/bin/pest --filter=RuteIdempoten 
git add tests/Feature/Sistem/RuteIdempotenTest.php 
git commit -m "test: setiap rute POST berkunci idempotensi atau beralasan" 
git add app/Balapan/KunciGanda.php app/Balapan/RebutanCheckout.php app/Balapan/BatalSaatCheckout.php 
git commit -m "test: skenario balapan lintas domain" 
git add .github/workflows/ci.yml 
git commit -m "ci: jalur kedua di MySQL dengan uji balapan" 
git push -u origin sesi-4/balapan-ci-sekar 
gh pr create --base main --draft --web 
 
Keluaran — uji klasifikasi di cabang Sekar, saat masih draf (diringkas) 
$ ./vendor/bin/pest tests/Feature/Sistem/RuteIdempotenTest.php 
  
   FAIL  Tests\Feature\Sistem\RuteIdempotenTest 
  ⨯ it mencatat setiap rute POST pada tepat satu daftar                  0.16s 
  ⨯ it memasang idempoten pada setiap rute berkunci, di belakang kunci…  0.01s 
  ✓ it tidak memasang idempoten pada rute yang dinyatakan tidak memerlu… 0.01s 
  ✓ it menuliskan alasan untuk setiap rute tanpa kunci                   0.01s 
  Tercatat tetapi rutenya tidak ada: 
    POST api/v1/checkout 
    POST api/v1/toko-saya/produk/{id}/stok 
    POST api/v1/alamat 
    POST api/v1/webhook/pembayaran 
  
  Tanpa idempoten atau urutannya salah: 
    POST api/v1/checkout 
    POST api/v1/toko-saya/produk/{id}/stok 
  
  Tests:    2 failed, 2 passed (15 assertions) 
 
Draf ini merah, dan memang harus begitu. Daftarnya menyebut empat rute yang belum ada di cabang Sekar: 
checkout, alamat, webhook, dan penyesuaian stok, masing-masing masih di cabang pemiliknya. Sekar 
menuliskannya pada deskripsi PR: "Merah sampai B1 sampai B4 masuk. Jangan ditinjau sebelum hijau." Papan sesi 
mencatat B5 menunggu keempatnya. 
Langkah 12 — Mengunggah, Me-merge Jendela B, dan Satu Kebuntuan (Semua, dipimpin 
Dimas) 
12.1 H+2: lima draf. Empat draf hijau pada tugas kualitas, satu merah dengan alasan tertulis. Setiap deskripsi PR 
memuat keluaran skenario balapannya dari MySQL, baik versi yang melanggar maupun versi akhir. 

12.2 H+5: B1 sampai B4. Urutannya mengikuti papan sesi. Setiap penulis menyegarkan cabangnya sesaat sebelum 
gilirannya. 
Terminal — penulis PR, sesaat sebelum gilirannya 
git fetch origin 
git rebase origin/main 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
php artisan migrate:fresh --seed 
php artisan lapak:balapan <skenario-sendiri> 
git push --force-with-lease 
 
Tidak ada konflik teks. Jumlah uji pada main setelah setiap merge: 332, 357, 369, lalu 388. Setelah B3 masuk, 
checkout di main untuk pertama kalinya mengunci stok. 
12.3 B5 disegarkan: Pest hijau, balapan merah. Sekar menyegarkan cabangnya. Uji klasifikasi kini hijau, dan seluruh 
uji Pest lulus: 392. Ia menjalankan seluruh skenario pada MySQL. 
Terminal — Sekar 
php artisan migrate:fresh --seed 
php artisan lapak:balapan --semua 
 
Delapan skenario lulus. Satu tidak: 
Keluaran — skenario batal-saat-checkout pada main yang memuat B1 sampai B4 
$ php artisan lapak:balapan batal-saat-checkout 
  
batal-saat-checkout: Pembatalan otomatis bertemu pembelian produk yang sama: tidak ada kebuntuan 
+---------------------------------------------------------------------------+---------------+ 
| Hasil                                                                     | Jumlah proses | 
+---------------------------------------------------------------------------+---------------+ 
| GALAT SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when... | 3             | 
| pembeli: beli 2                                                           | 3             | 
| penjadwal: batal                                                          | 6             | 
+---------------------------------------------------------------------------+---------------+ 
PELANGGARAN: Ada proses yang gagal, misalnya karena kebuntuan (deadlock). 
 
Galat 1213 adalah cara MySQL melaporkan kebuntuan. Ia menemukan dua transaksi yang saling menunggu, lalu 
mematikan salah satunya supaya yang lain dapat selesai. Empat pembeli pada keluaran itu adalah transaksi yang 
dimatikan. Di dunia nyata keempatnya menerima 500 saat menekan bayar, tanpa kesalahan apa pun dari pihak 
mereka. 
MySQL menyimpan laporan kebuntuan terakhir. Sekar membacanya sebagai pengguna root: 
Terminal — Sekar 
mysql -u root -p -e "SHOW ENGINE INNODB STATUS\G" \ 
  | sed -n '/LATEST DETECTED DEADLOCK/,/WE ROLL BACK/p' 
 
Keluaran — laporan kebuntuan InnoDB (diringkas dan diberi keterangan) 
*** (1) TRANSACTION: 
update `produk` set `stok` = `stok` + 1, ... where `produk`.`id` = 14 
*** (1) HOLDS THE LOCK(S): 
RECORD LOCKS index PRIMARY of table `lapak`.`produk` lock_mode X locks rec but not gap 
 0: len 8; hex 000000000000000f                                  <- produk nomor 15 
*** (1) WAITING FOR THIS LOCK TO BE GRANTED: 
RECORD LOCKS index PRIMARY of table `lapak`.`produk` lock_mode X locks rec but not gap waiting 
 0: len 8; hex 000000000000000e                                  <- produk nomor 14 
  
*** (2) TRANSACTION: 
select * from `produk` where `id` in (14, 15) order by `id` asc for update 
*** (2) HOLDS THE LOCK(S): 
RECORD LOCKS index PRIMARY of table `lapak`.`produk` lock_mode X locks rec but not gap 
 0: len 8; hex 000000000000000e                                  <- produk nomor 14 

*** (2) WAITING FOR THIS LOCK TO BE GRANTED: 
RECORD LOCKS index PRIMARY of table `lapak`.`produk` lock_mode X locks rec but not gap waiting 
 0: len 8; hex 000000000000000f                                  <- produk nomor 15 
  
*** WE ROLL BACK TRANSACTION (2) 
 
Laporan itu menyebut kedua pelakunya. Transaksi pertama adalah pembatalan otomatis: ia sedang mengembalikan 
stok produk 14, sudah memegang produk 15, dan menunggu produk 14. Transaksi kedua adalah pembelian: 
PenjagaStok::ambil() sudah memegang produk 14 dan menunggu produk 15. MySQL memilih mematikan 
transaksi kedua, yaitu pembeli. 
Sekar lalu membaca PembatalanOtomatis::batalkan() dan membandingkannya dengan tangga pada 
docs/PETA-SKEMA.md. 
Dua jalur atas dua produk yang sama 
Pembelian (PenjagaStok::ambil)             Pembatalan otomatis (versi B2) 
  kunci produk urut nomor:                   untuk setiap pesanan, untuk setiap butir: 
    produk kecil, lalu produk besar            kembalikan stok menurut urutan butir: 
                                               produk besar, lalu produk kecil 
  
waktu 1   memegang produk kecil              memegang produk besar 
waktu 2   meminta produk besar ... menunggu  meminta produk kecil ... menunggu 
 
Checkout mengunci produk urut nomor. Pembatalan mengembalikan stok menurut urutan butir pesanan, dan 
UPDATE atas sebuah baris juga menguncinya. Ketika urutan butir tidak sama dengan urutan nomor produk, kedua 
jalur mengunci dengan arah berlawanan. Seluruh uji Pest hijau karena Pest tidak pernah menjalankan dua jalur itu 
bersamaan. Tinjauan pun tidak menangkapnya: setiap baris batalkan() benar bila dibaca sendiri. 
12.4 P1: pemilik domain memperbaiki, di cabangnya sendiri. Sekar tidak menyentuh berkas Rani di dalam PR-nya. 
Ia membuka issue berisi keluaran di atas dan nama skenarionya, lalu menandai B5 menunggu. Rani membuka 
cabang dari main. 
app/Services/PembatalanOtomatis.php — perbaikan P1 
             } 
  
+            $kembali = []; 
+ 
             foreach ($checkout->pesanan()->with('butir')->orderBy('id')->get() as $pesanan) { 
                 $this->mesin->pindahkan( 
   ... 
  
                 foreach ($pesanan->butir as $butir) { 
-                    $this->stok->kembalikan($butir->produk_id, $butir->kuantitas); 
+                    $kembali[$butir->produk_id] = ($kembali[$butir->produk_id] ?? 0) 
+                        + $butir->kuantitas; 
                 } 
             } 
  
+            // Urutan kunci tim (docs/PETA-SKEMA.md): seluruh pesanan dulu, lalu produk urut nomor 
+            // menaik. Checkout mengunci produk dengan urutan yang sama. Bila di sini stok 
+            // dikembalikan menurut urutan butir, keduanya dapat saling menunggu selamanya. 
+            ksort($kembali); 
+ 
+            foreach ($kembali as $idProduk => $kuantitas) { 
+                $this->stok->kembalikan($idProduk, $kuantitas); 
+            } 
+ 
             return true; 
         }); 
 
Seluruh pesanan ditandai lebih dulu, dan kuantitas yang harus kembali dijumlahkan per produk. Setelah itu stok 
dikembalikan urut nomor produk. Tangga kunci terpenuhi dua kali: pesanan sebelum produk, dan produk urut 
nomor. 

tests/Feature/Belanja/PembatalanOtomatisTest.php — uji yang ditambahkan P1 
it('mengembalikan stok urut nomor produk, apa pun urutan butirnya', function () { 
    $checkout = Checkout::factory()->create(); 
    Pembayaran::factory()->for($checkout)->create(['batas_bayar_pada' => now()->subMinute()]); 
    $pesanan = Pesanan::factory()->for($checkout)->create(); 
    [$kecil, $tengah, $besar] = Produk::factory()->count(3)->create(['stok' => 0])->all(); 
  
    // Butir sengaja disimpan dari nomor produk terbesar. 
    foreach ([$besar, $kecil, $tengah] as $barang) { 
        ButirPesanan::factory()->for($pesanan)->create(['produk_id' => $barang->id]); 
    } 
  
    DB::enableQueryLog(); 
    app(PembatalanOtomatis::class)->batalkan($checkout->id); 
  
    $urutan = collect(DB::getQueryLog()) 
        // Tanda kutip nama tabel berbeda antara SQLite dan MySQL, maka dicocokkan dengan titik. 
        ->filter(fn (array $kueri) => preg_match('/^update .produk. /', $kueri['query']) === 1) 
        ->map(fn (array $kueri) => last($kueri['bindings'])) 
        ->values() 
        ->all(); 
  
    expect($urutan)->toBe([$kecil->id, $tengah->id, $besar->id]); 
}); 
 
Kebuntuan tidak dapat ditimbulkan di dalam Pest, tetapi sebabnya dapat diperiksa: uji ini merekam kueri yang 
dijalankan dan menuntut UPDATE atas produk terjadi dengan nomor menaik. Bila besok seseorang mengembalikan 
perulangan lama, uji ini merah di SQLite dalam hitungan detik, tanpa menunggu tugas MySQL. 
Terminal — Rani 
git switch main 
git pull origin main 
git switch -c sesi-4/urutan-kunci-rani 
# sunting PembatalanOtomatis.php dan PembatalanOtomatisTest.php 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git add app/Services/PembatalanOtomatis.php tests/Feature/Belanja/PembatalanOtomatisTest.php 
git commit -m "fix: kembalikan stok urut nomor produk agar tidak buntu dengan checkout" 
git push -u origin sesi-4/urutan-kunci-rani 
gh pr create --base main --web 
 
Bagas meninjau PR #45, sebagai peninjau lingkar Rani sekaligus pemilik PenjagaStok. main setelah merge: 389 uji 
lulus. 
12.5 B5 masuk. Sekar menyegarkan cabangnya sekali lagi dan mengulang skenarionya. 
Keluaran — skenario yang sama setelah P1 masuk 
$ php artisan lapak:balapan batal-saat-checkout 
  
batal-saat-checkout: Pembatalan otomatis bertemu pembelian produk yang sama: tidak ada kebuntuan 
+------------------+---------------+ 
| Hasil            | Jumlah proses | 
+------------------+---------------+ 
| pembeli: beli 2  | 6             | 
| penjadwal: batal | 6             | 
+------------------+---------------+ 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
 
Dimas meninjau dan me-merge PR #44. main: 393 uji lulus. Setelah itu ia membuka Settings > Branches, 
menyunting aturan perlindungan main, dan menambahkan kualitas-mysql pada daftar pemeriksaan status wajib 
di samping kualitas. Nama pemeriksaan baru biasanya baru dapat dipilih pada daftar itu setelah tugasnya pernah 
berjalan di repositori. 
PENTING — Yang diajarkan kebuntuan ini 

Urutan kunci tertulis di dokumen sejak hari pertama, disepakati lima orang, dan tetap dilanggar oleh kode yang lulus 
uji dan lulus tinjauan. Tiga hal menangkapnya: skenario yang sengaja mempertemukan dua jalur, basis data yang 
sama dengan produksi, dan kebiasaan menjalankan keduanya sebelum merge. Tiga hal menyelesaikannya dengan 
tertib: temuan dilaporkan beserta keluarannya, perbaikan dikerjakan pemilik berkas di cabang tersendiri, dan 
sebabnya dikunci dengan uji yang cepat. 
 
TIPS — Mengulang transaksi yang dimatikan MySQL 
DB::transaction() menerima argumen kedua berupa jumlah percobaan. Bila MySQL mematikan sebuah transaksi 
karena kebuntuan, Laravel mengulang transaksi terluar sampai jumlah itu habis. Ini jaring pengaman yang wajar 
dipasang pada pekerjaan latar seperti pembatalan otomatis. Ia tidak menggantikan urutan kunci: transaksi yang 
diulang tetap membuat pembeli menunggu, dan transaksi bersarang, seperti checkout di bawah middleware 
idempoten, tidak diulang. 
 
 
 

Bagian 4 — Penutup Sesi 
 
Langkah 13 — Memverifikasi main dan Menjalankan Alur Sesi (Dimas, disaksikan Semua) 
Terminal — verifikasi penutup 
git switch main 
git pull origin main 
composer install 
php artisan migrate:fresh --seed 
./vendor/bin/pint --test 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
php artisan lapak:balapan --semua | grep -E "^[a-z-]+:|LULUS|PELANGGARAN" 
php artisan migrate:fresh --seed 
php artisan lapak:periksa-buku 
php artisan route:list --path=api/v1 
 
Hasil yang benar: 393 uji lulus dengan 1514 asersi pada SQLite maupun MySQL, sembilan skenario balapan lulus, 
buku besar seimbang dengan 6 mutasi, dan 43 rute. migrate:fresh --seed yang kedua membersihkan data 
percobaan yang ditinggalkan uji balapan. 
Keluaran — perintah kedelapan: sembilan skenario pada main, disaring dengan grep 
alamat-pertama: Pembeli baru menyimpan alamat dari banyak tab: satu utama, tidak melebihi batas 
LULUS: 8 proses serentak, tidak ada aturan yang dilanggar. 
batal-saat-checkout: Pembatalan otomatis bertemu pembelian produk yang sama: tidak ada kebuntuan 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
keranjang-ganda: Satu keranjang di-checkout dari banyak tab, kunci berbeda: tepat satu checkout 
LULUS: 6 proses serentak, tidak ada aturan yang dilanggar. 
kunci-ganda: Checkout yang sama diulang serentak dengan satu Idempotency-Key: efeknya satu kali 
LULUS: 6 proses serentak, tidak ada aturan yang dilanggar. 
penyesuaian-stok: Penjual menambah stok selagi pembeli berbelanja: tidak ada perubahan yang hilang 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
rebutan-checkout: Dua belas pembeli checkout serentak lewat HTTP untuk 5 barang 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
rebutan-stok: Dua belas pembeli berebut 5 barang: terjual tepat sebanyak itu 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
serempak: Semua proses mulai dalam jendela 250 milidetik 
LULUS: 8 proses serentak, tidak ada aturan yang dilanggar. 
webhook-ganda: Kabar pembayaran yang sama tiba serentak: dana masuk escrow tepat satu kali 
LULUS: 6 proses serentak, tidak ada aturan yang dilanggar. 
 
Berikutnya alur sesi dijalankan terhadap server sungguhan dengan php artisan serve. Akun contoh yang dipakai: 
Akun Peran Catatan 
andi@lapak.test pembeli Nomor akun 8. Dua alamat: nomor 1 (Rumah, utama) dan nomor 2 (Kantor). 
dewi@lapak.test pembeli Nomor akun 9. Satu alamat: nomor 3. 
sri@lapak.test pembeli, penjual Pemilik toko Batik Laweyan Bu Sri. Produk nomor 1 sampai 4. 
keuangan@lapak.test keuangan Membuka buku besar 
admin@lapak.test admin Tidak memegang pesanan.buat 
 
Terminal kedua — satu kunci untuk setiap pelaku 
B=http://127.0.0.1:8000/api/v1 
J="Content-Type: application/json" 
  
masuk() { 

  curl -s -X POST $B/masuk -H "$J" -d "{ 
      \"surel\": \"$1@lapak.test\", 
      \"kata_sandi\": \"LapakContoh#2026\", 
      \"nama_perangkat\": \"Laptop $1\" 
    }" | jq -r .data.kunci 
} 
  
ANDI=$(masuk andi); DEWI=$(masuk dewi); SRI=$(masuk sri) 
KEUANGAN=$(masuk keuangan); ADMIN=$(masuk admin) 
 
13.1 Dari keranjang menjadi pesanan. Andi mengisi keranjang dari dua toko, lalu mencoba checkout tanpa 
Idempotency-Key. 
Terminal kedua 
curl -s $B/alamat -H "Authorization: Bearer $ANDI" | jq -c '.data[] | {id, label, utama, jalan}' 
  
curl -s -X PUT $B/keranjang/butir/2 -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -d '{"kuantitas":2}' > /dev/null 
curl -s -X PUT $B/keranjang/butir/6 -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -d '{"kuantitas":1}' \ 
  | jq -c '.data | (.pesanan[] | {toko, total}), {total_bayar: .total_bayar.tampilan}' 
  
curl -s -X POST $B/checkout -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -d '{"alamat_id":1}' | jq .galat 
 
Respons — alamat Andi 
{"id":1,"label":"Rumah","utama":true,"jalan":"Jl. Slamet Riyadi No. 12"} 
{"id":2,"label":"Kantor","utama":false,"jalan":"Jl. Ir. Sutami No. 36A"} 
 
Respons — keranjang dua toko 
{"toko":"Batik Laweyan Bu Sri","total":382000} 
{"toko":"Oleh-Oleh Mbak Wulan","total":88000} 
{"total_bayar":"Rp 470.000"} 
 
Respons — checkout tanpa Idempotency-Key: 422 
{ 
  "kode": "LP-11", 
  "pesan": "Permintaan ini wajib membawa header Idempotency-Key: 16 sampai 64 huruf, angka, garis bawah, atau 
tanda hubung.", 
  "rincian": null 
} 
 
Sekarang dengan kunci. Kuncinya dibuat klien, satu untuk satu niat. Badannya sengaja menyelipkan dua angka 
palsu. 
Terminal kedua 
K=$(openssl rand -hex 16) 
BADAN='{"alamat_id":1,"total_rupiah":1,"ongkir_rupiah":0}' 
  
curl -s -X POST $B/checkout -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -H "Idempotency-Key: $K" -d "$BADAN" | tee /tmp/checkout.json \ 
  | jq '.data | {kode, total: .total.tampilan, 
      pembayaran: (.pembayaran | {kode, status, batas_bayar_pada}), 
      pesanan: [.pesanan[] | {nomor, status, toko: .toko.nama, 
                              total: .total.tampilan, kirim_ke: .alamat_kirim.jalan}]}' 
 
Respons — 201: satu checkout, satu tagihan, dua pesanan (dipilih dengan jq) 
{ 
  "kode": "01M4A4MWM8KBWRXAGESM207JJ6", 
  "total": "Rp 470.000", 
  "pembayaran": { 

    "kode": "BYR-01M4A4MWMN9T42FP7FFX3411KG", 
    "status": "menunggu", 
    "batas_bayar_pada": "2026-10-08T02:56:25+00:00" 
  }, 
  "pesanan": [ 
    { 
      "nomor": "LPK-20261007-000001", 
      "status": "menunggu_pembayaran", 
      "toko": "Batik Laweyan Bu Sri", 
      "total": "Rp 382.000", 
      "kirim_ke": "Jl. Slamet Riyadi No. 12" 
    }, 
    { 
      "nomor": "LPK-20261007-000002", 
      "status": "menunggu_pembayaran", 
      "toko": "Oleh-Oleh Mbak Wulan", 
      "total": "Rp 88.000", 
      "kirim_ke": "Jl. Slamet Riyadi No. 12" 
    } 
  ] 
} 
 
Total yang ditagih Rp 470.000, bukan Rp 1. Dua pesanan lahir, satu per toko, dengan satu tagihan untuk keduanya. 
Batas bayarnya tepat 24 jam setelah checkout. 
13.2 Tombol bayar tertekan dua kali. Permintaan yang sama, dengan kunci yang sama, dikirim sekali lagi. 
Terminal kedua 
curl -s -D /tmp/kepala.txt -X POST $B/checkout -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -H "Idempotency-Key: $K" -d "$BADAN" > /tmp/ulang.json 
head -1 /tmp/kepala.txt; grep -i idempotency /tmp/kepala.txt 
jq -c '{kode: .data.kode, pesanan: [.data.pesanan[].nomor]}' /tmp/ulang.json 
cmp -s /tmp/checkout.json /tmp/ulang.json && echo "badan kedua jawaban identik" 
curl -s "$B/produk?per_halaman=60" | jq -c '[.data[] | select(.id==2 or .id==6) | {id, stok}]' 
 
Keluaran — jawaban yang sama, efek satu kali (diringkas) 
HTTP/1.1 201 Created 
Idempotency-Replayed: true 
{"kode":"01M4A4MWM8KBWRXAGESM207JJ6","pesanan":["LPK-20261007-000001","LPK-20261007-000002"]} 
badan kedua jawaban identik 
stok kain: 23, stok abon: 34 
{"isi_keranjang":0} 
 
Kode checkout dan nomor pesanannya sama, badan kedua jawaban identik sampai byte terakhir, dan stok hanya 
berkurang satu kali: kain dari 25 menjadi 23, abon dari 35 menjadi 34. Kunci yang sama untuk permintaan lain 
ditolak: 
Terminal kedua 
curl -s -X POST $B/checkout -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -H "Idempotency-Key: $K" -d '{"alamat_id":2}' | jq .galat 
 
Respons — 422: kunci lama, badan berbeda 
{ 
  "kode": "LP-11", 
  "pesan": "Idempotency-Key ini sudah dipakai untuk permintaan yang berbeda. Buat kunci baru.", 
  "rincian": null 
} 
 
13.3 Pesanan orang lain tidak ada. 
Terminal kedua 
curl -s $B/pesanan -H "Authorization: Bearer $ANDI" \ 
  | jq -c '.data[] | {nomor, status, total: .total.tampilan}' 

Keluaran — pesanan Andi, lalu tiga percobaan Dewi (status HTTP) 
{"nomor":"LPK-20261007-000002","status":"menunggu_pembayaran","total":"Rp 88.000"} 
{"nomor":"LPK-20261007-000001","status":"menunggu_pembayaran","total":"Rp 382.000"} 
Dewi GET /pesanan/LPK-20261007-000001: 404 
Dewi GET /pesanan/LPK-20260101-999999: 404 
Dewi GET /pembayaran/<kode tagihan Andi>: 404 
 
Nomor pesanan Andi yang benar-benar ada, nomor yang tidak pernah ada, dan kode tagihan Andi dijawab sama 
kepada Dewi: 404. 
13.4 Siapa yang boleh berkata lunas. Tanda tangan dihitung dengan openssl, persis seperti yang dilakukan 
TandaTanganGateway::buat(). 
Terminal kedua 
BYR=$(jq -r .data.pembayaran.kode /tmp/checkout.json) 
RAHASIA=rahasia-gerbang-untuk-latihan 
  
kirim() {   # $1 = badan, $2 = rahasia penanda tangan 
  T=$(date +%s) 
  TANDA=$(printf '%s.%s' "$T" "$1" | openssl dgst -sha256 -hmac "$2" | awk '{print $NF}') 
  curl -s -X POST $B/webhook/pembayaran -H "$J" \ 
    -H "X-Gateway-Signature: t=$T,v1=$TANDA" -d "$1" 
} 
  
KABAR="{\"id_peristiwa\":\"evt_demo_001\",\"kode_pembayaran\":\"$BYR\",\"jumlah_rupiah\":470000}" 
SATU="{\"id_peristiwa\":\"evt_demo_000\",\"kode_pembayaran\":\"$BYR\",\"jumlah_rupiah\":1}" 
  
curl -s -X POST $B/webhook/pembayaran -H "$J" -d "$KABAR" | jq -c '[.galat.kode]' 
kirim "$KABAR" "tebakan-saya" | jq -c '[.galat.kode]' 
  
T=$(( $(date +%s) - 600 ))     # kabar sah yang direkam sepuluh menit lalu 
TANDA=$(printf '%s.%s' "$T" "$KABAR" | openssl dgst -sha256 -hmac "$RAHASIA" | awk '{print $NF}') 
curl -s -X POST $B/webhook/pembayaran -H "$J" \ 
  -H "X-Gateway-Signature: t=$T,v1=$TANDA" -d "$KABAR" | jq -c '[.galat.kode]' 
  
kirim "$SATU"  "$RAHASIA"     | jq -c '[.galat.kode]' 
kirim "$KABAR" "$RAHASIA"     | jq -c .data 
kirim "$KABAR" "$RAHASIA"     | jq -c .data 
 
Keluaran — enam kiriman ke pintu webhook (diberi label) 
tanpa tanda tangan : ["TANDA_TANGAN_SALAH"] 
rahasia tebakan    : ["TANDA_TANGAN_SALAH"] 
rekaman 10 menit   : ["TANDA_TANGAN_SALAH"] 
sah, jumlah Rp 1   : ["JUMLAH_TIDAK_COCOK"] 
sah                : {"hasil":"dilunasi"} 
sah, dikirim ulang : {"hasil":"sudah_diproses"} 
 
Kiriman ketiga adalah kabar yang sah, ditandatangani dengan rahasia yang benar, tetapi dengan t sepuluh menit 
yang lalu. Ia ditolak dengan jawaban yang sama seperti tebakan rahasia. Kabar bertanda tangan sah untuk Rp 1 
lolos pemeriksaan pengirim, lalu ditolak pencocokan jumlah. Kabar yang benar melunasi tepat satu kali. 
13.5 Uangnya ada di mana. 
Terminal kedua 
NO=$(jq -r '.data.pesanan[0].nomor' /tmp/checkout.json) 
curl -s $B/pesanan/$NO -H "Authorization: Bearer $ANDI" \ 
  | jq -c '.data | {nomor: .pesanan.nomor, status: .pesanan.status, tagihan: .pembayaran.status}' 
curl -s $B/buku-besar/dompet -H "Authorization: Bearer $KEUANGAN" \ 
  | jq -c '{seimbang: .meta.seimbang}, (.data[] | {kode, saldo: .saldo.tampilan})' 
curl -s $B/buku-besar/dompet/2/mutasi -H "Authorization: Bearer $KEUANGAN" \ 
  | jq -c '.data[] | select(.jenis=="dana_masuk" and (.rujukan|startswith("LPK-2026"))) 
           | {jenis, jumlah: .jumlah.tampilan, rujukan}' 
 

Respons — pesanan dibayar, escrow bertambah Rp 470.000, dua mutasi merujuk dua pesanan 
{"nomor":"LPK-20261007-000001","status":"dibayar","tagihan":"lunas"} 
{"seimbang":true} 
{"kode":"eksternal","saldo":"-Rp 852.000"} 
{"kode":"escrow","saldo":"Rp 470.000"} 
{"kode":"pendapatan","saldo":"Rp 18.500"} 
{"kode":"penjual:4","saldo":"Rp 363.500"} 
{"jenis":"dana_masuk","jumlah":"+Rp 88.000","rujukan":"LPK-20261007-000002"} 
{"jenis":"dana_masuk","jumlah":"+Rp 382.000","rujukan":"LPK-20261007-000001"} 
 
Keluaran — php artisan lapak:periksa-buku 
+------------+------------+------------+ 
| Dompet     | Jenis      | Saldo (Rp) | 
+------------+------------+------------+ 
| eksternal  | eksternal  | -852.000   | 
| escrow     | escrow     | 470.000    | 
| pendapatan | pendapatan | 18.500     | 
| penjual:4  | penjual    | 363.500    | 
+------------+------------+------------+ 
Buku besar seimbang: 10 mutasi, 0 pelanggaran. 
 
Rp 470.000 berpindah dari dompet eksternal ke escrow sebagai dua pemindahan, masing-masing merujuk nomor 
pesanannya. Penjual belum menerima apa pun: saldo penjual:4 tidak berubah. Pelepasan dana ke penjual adalah 
pekerjaan Sesi 5. 
13.6 Checkout yang tidak dibayar. Dewi membeli dua kap lampu rotan, produk nomor 10, lalu tidak membayar. 
Menunggu 24 jam tidak praktis, sehingga batas bayarnya dimundurkan lewat klien MySQL. 
Terminal kedua 
curl -s -X PUT $B/keranjang/butir/10 -H "Authorization: Bearer $DEWI" -H "$J" \ 
  -d '{"kuantitas":2}' > /dev/null 
curl -s -X POST $B/checkout -H "Authorization: Bearer $DEWI" -H "$J" \ 
  -H "Idempotency-Key: $(openssl rand -hex 16)" -d '{"alamat_id":3}' > /tmp/dewi.json 
BYR2=$(jq -r .data.pembayaran.kode /tmp/dewi.json) 
  
php artisan lapak:batalkan-kedaluwarsa 
mysql -u lapak -p lapak -e "UPDATE pembayaran 
  SET batas_bayar_pada = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE kode = '$BYR2'" 
php artisan lapak:batalkan-kedaluwarsa 
php artisan lapak:simulasi-bayar $BYR2 
 
Keluaran — stok, dua kali pembatalan, status pesanan, lalu pembayaran yang terlambat (diberi label) 
stok kap lampu sebelum checkout: 6, sesudah: 4 
$ php artisan lapak:batalkan-kedaluwarsa 
0 checkout dibatalkan dan stoknya dikembalikan. 
$ (batas bayar dimundurkan lewat klien MySQL) 
$ php artisan lapak:batalkan-kedaluwarsa 
1 checkout dibatalkan dan stoknya dikembalikan. 
stok kap lampu setelah pembatalan: 6 
{"status":"dibatalkan","alasan_batal":"Tidak dibayar sampai batas waktu","tagihan":"kedaluwarsa"} 
$ php artisan lapak:simulasi-bayar <kode tagihan Dewi> 
Kiriman ke-1: HTTP 409 
{"sukses":false,"galat":{"kode":"LP-10","pesan":"Tagihan ini sudah kedaluwarsa.","rincian":null}} 
 
Stok kembali dari 4 menjadi 6, pesanan berstatus dibatalkan dengan alasannya, dan tagihan berstatus kedaluwarsa. 
Pembayaran yang datang sesudahnya ditolak 409. Pakai UTC_TIMESTAMP(), bukan NOW(): Laravel menyimpan 
waktu dalam UTC, sedangkan NOW() mengikuti zona waktu server MySQL Anda. 
13.7 Sisa uji manual. Jalankan seluruh baris berikut secara berurutan, melanjutkan keadaan di atas, dan catat status 
serta galat.kode yang Anda terima. Setiap POST yang memerlukannya dikirim dengan Idempotency-Key baru, 
kecuali disebutkan lain. 

No Pelaku Permintaan Harapan 
1 Bu Sri PATCH /toko-saya/produk/2 dengan {"stok":99} 422 VALIDASI; pesannya menunjuk pintu penyesuaian stok 
2 Bu Sri POST /toko-saya/produk/2/stok dengan {"selisih":5} 200, stok menjadi 28 
3 Bu Sri Permintaan nomor 2 sekali lagi, kunci yang sama 200, stok tetap 28, header Idempotency-Replayed: true 
4 Bu Sri POST /toko-saya/produk/2/stok dengan {"selisih":-100} 409 LP-4; rinciannya menyebut stok sekarang 
5 Bu Sri POST /toko-saya/produk/9/stok dengan {"selisih":5} 404: produk toko lain 
6 Andi POST /toko-saya/produk/2/stok dengan {"selisih":5} 403 DITOLAK: bukan penjual 
7 Bu Sri PUT /keranjang/butir/2 dengan {"kuantitas":1} 422 LP-5: produk toko sendiri 
8 Admin POST /checkout 403 DITOLAK: akun staf tidak berbelanja 
9 Andi Isi keranjang dengan produk 8, lalu POST /checkout dengan {"alamat_id":3} 422 VALIDASI, "Alamat tidak ditemukan.": alamat nomor 3 milik Dewi 
10 Dewi 
Isi keranjang dengan 5 buah produk 1, jadikan 
sesinya tidak segar (lihat di bawah), lalu POST 
/checkout 
403 LP-15. Stok kemeja tetap 8 dan keranjang 
tetap berisi. 
11 Dewi 
POST /sesi/konfirmasi, lalu checkout nomor 
10 dengan kunci sesi baru dan Idempotency-
Key yang sama 
201, total Rp 2.437.000, stok kemeja menjadi 3 
12 Andi POST /alamat empat kali Tiga yang pertama 201; yang keempat 422 ALAMAT_PENUH 
13 Andi DELETE /alamat/1 200; alamat Kantor menjadi utama 
14 Andi GET /pesanan/<nomor pesanan pertama> 200; alamat_kirim.jalan tetap "Jl. Slamet Riyadi No. 12" 
15 Andi DELETE /alamat/3 404: alamat Dewi 
16 Andi POST /checkout dengan {"alamat_id":2} dua belas kali dalam satu menit Yang pertama 201, berikutnya 422 KERANJANG_KOSONG, lalu 429 TERLALU_SERING setelah jatah sepuluh per menit habis 
 
Untuk baris 10, sesi Dewi dijadikan tidak segar tanpa menunggu sepuluh menit: 
Terminal kedua 
mysql -u lapak -p lapak -e "UPDATE sesi 
  SET dikonfirmasi_pada = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE pengguna_id = 9" 
 
Baris 10 dan 11 memperlihatkan dua rancangan bekerja bersama. Checkout yang ditolak karena sesi tidak segar 
tidak meninggalkan apa pun, termasuk catatan kunci idempotensinya. Setelah Dewi mengonfirmasi kata sandi, klien 
mengirim ulang permintaan yang sama dengan Idempotency-Key yang sama, dan kali ini diproses seperti 
permintaan baru. Baris 13 dan 14 memperlihatkan mengapa alamat disalin: alamat yang dipakai pesanan pertama 
sudah dihapus, dan pesanannya tidak berubah. 
Tutup dengan php artisan lapak:periksa-buku. Hasil yang benar: seimbang, 10 mutasi. 

Sejak sesi ini ada dua nilai yang harus ditutupi: kunci sesi dan LAPAK_GATEWAY_RAHASIA. Idempotency-Key bukan 
rahasia dan boleh terlihat. 
 
Langkah 14 — PR Penutup, Tag, Rekap, dan Serah Terima (Dimas, ditinjau Sekar) 
14.1 Papan sesi dan tempat penegakan aturan. Dimas menutup sesi dari cabang sesi-4/penutup-dimas. Kolom 
Status terisi, baris P1 ditambahkan pada tabel jendela B, dan catatan penutup memuat temuan serta titipan untuk 
Sekar. Sepuluh baris docs/ATURAN-BISNIS.md diperbarui: 
Kode Ditegakkan di 
LP-4 Produk::scopeTampil, PenyusunBelanja, PenjagaStok; skenario rebutan-stok, rebutan-checkout, dan penyesuaian-stok 
LP-5 PenyusunBelanja::pilah, LayananKeranjang::aturButir, LayananCheckout 
LP-6 SimulasiBelanjaRequest, PenyusunBelanja, CheckoutRequest, kolom beku pada butir_pesanan, LayananPembayaran::terima 
LP-7 PenghitungBelanja, LayananCheckout, unique pada pembayaran.checkout_id 
LP-9 PenomorPesanan, Pesanan::booted, unique pada pesanan.nomor 
LP-10 Sebagian: AturanTransisi, MesinStatusPesanan, PembatalanOtomatis; pintu penjual dan pembatalan oleh pembeli menyusul 
LP-11 Middleware idempoten, RuteIdempotenTest; skenario kunci-ganda 
LP-12 Sebagian: dana masuk escrow pada LayananPembayaran::terima; pelepasannya menyusul 
LP-13 BukuBesar, MutasiSaldo::booted, lapak:periksa-buku dengan lima invarian 
LP-15 Sebagian: ditambah PenjagaTransaksi untuk checkout bernilai besar 
 
Terminal — Dimas: PR penutup, lalu tag 
git switch -c sesi-4/penutup-dimas 
git add docs/sesi/SESI-4.md docs/ATURAN-BISNIS.md 
git commit -m "docs: penutup sesi 4 dan tempat penegakan aturan" 
git push -u origin sesi-4/penutup-dimas 
gh pr create --base main --web 
  
# setelah Sekar menyetujui dan PR di-merge 
git switch main 
git pull origin main 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git tag -a sesi-4 -m "Sesi 4: keamanan transaksi" 
git push origin sesi-4 
scripts/rekap-sesi.sh 4 
 
Keluaran — scripts/rekap-sesi.sh 4 
== Commit per anggota (sesi-3..sesi-4, tanpa merge commit) 
     9    Dimas 
     7    Rani 
     6    Sekar 
     5    Bagas 
     5    Bambang 
  
== Baris ditambah dan dihapus per anggota 
Bagas        +609 -4 
Bambang      +742 -4 
Dimas        +1153 -28 
Rani         +1631 -14 

Sekar        +1178 -1 
  
== PR yang masuk ke main 
  Merge pull request #46 from tim-lapak/sesi-4/penutup-dimas 
  Merge pull request #44 from tim-lapak/sesi-4/balapan-ci-sekar 
  Merge pull request #45 from tim-lapak/sesi-4/urutan-kunci-rani 
  Merge pull request #43 from tim-lapak/sesi-4/webhook-dimas 
  Merge pull request #42 from tim-lapak/sesi-4/kunci-stok-bagas 
  Merge pull request #41 from tim-lapak/sesi-4/checkout-rani 
  Merge pull request #40 from tim-lapak/sesi-4/alamat-bambang 
  Merge pull request #39 from tim-lapak/sesi-4/kontrak-pembayaran-dimas 
  Merge pull request #38 from tim-lapak/sesi-4/kontrak-pesanan-rani 
  Merge pull request #37 from tim-lapak/sesi-4/kontrak-stok-bagas 
  Merge pull request #36 from tim-lapak/sesi-4/kontrak-alamat-bambang 
  Merge pull request #35 from tim-lapak/sesi-4/kontrak-idempoten-sekar 
  Merge pull request #34 from tim-lapak/sesi-4/pembuka-dimas 
 
Rani menulis baris terbanyak: checkout adalah pusat sesi ini, dan hampir separuh barisnya adalah uji dan skenario 
balapan. Dimas memiliki commit terbanyak karena menjadi integrator dan karena cabang kontraknya memerlukan 
satu commit perbaikan setelah rebase --onto. Bagas menulis paling sedikit, tetapi dua dari sembilan skenario 
balapan miliknya, dan satu lubang yang ia temukan sudah ada sejak Sesi 2. Seperti pada tiga sesi sebelumnya, 
angka-angka ini bahan percakapan, bukan nilai. 
Keluaran — tiga belas PR Sesi 4 
1b54184 Merge pull request #46 from tim-lapak/sesi-4/penutup-dimas 
e3c4007 Merge pull request #44 from tim-lapak/sesi-4/balapan-ci-sekar 
ae96f3f Merge pull request #45 from tim-lapak/sesi-4/urutan-kunci-rani 
ce046fc Merge pull request #43 from tim-lapak/sesi-4/webhook-dimas 
8996421 Merge pull request #42 from tim-lapak/sesi-4/kunci-stok-bagas 
1750615 Merge pull request #41 from tim-lapak/sesi-4/checkout-rani 
0c6dbe6 Merge pull request #40 from tim-lapak/sesi-4/alamat-bambang 
9478772 Merge pull request #39 from tim-lapak/sesi-4/kontrak-pembayaran-dimas 
1987884 Merge pull request #38 from tim-lapak/sesi-4/kontrak-pesanan-rani 
f1a0846 Merge pull request #37 from tim-lapak/sesi-4/kontrak-stok-bagas 
4ed6ab2 Merge pull request #36 from tim-lapak/sesi-4/kontrak-alamat-bambang 
9b5fb12 Merge pull request #35 from tim-lapak/sesi-4/kontrak-idempoten-sekar 
8a79889 Merge pull request #34 from tim-lapak/sesi-4/pembuka-dimas 
 
Urutan pada keluaran terakhir adalah urutan merge. PR #45 berada di bawah PR #44 karena masuk lebih dulu, 
walau dibuka belakangan. 
14.2 Serah terima. Dimas dan Sekar mencentang bersama: 
1. main hijau pada SQLite dan MySQL, tag sesi-4 ada di remote, tidak ada PR terbuka dan tidak ada cabang 
sesi-4/* yang tersisa. 
2. php artisan lapak:balapan --semua lulus pada mesin setiap anggota, dan kualitas-mysql menjadi 
pemeriksaan wajib pada main. 
3. Sekar mengetahui lima titipan pada papan sesi: pembayaran yang tiba setelah tagihan kedaluwarsa masih 
ditolak dan belum dikembalikan; pintu penjual, konfirmasi terima, dan pembatalan oleh pembeli belum ada; 
setiap rute POST baru wajib masuk RuteIdempotenTest; catatan kunci_idempotensi belum pernah 
dipangkas; pelunasan dan pembatalan otomatis belum masuk log audit. 
4. Sekar sudah menyalin kerangka papan sesi menjadi docs/sesi/SESI-5.md. 
 
 

G. LATIHAN MANDIRI 
Setiap latihan dikerjakan pemilik domainnya lewat cabang dan PR tersendiri, ditinjau menurut lingkar Sesi 4. Setiap 
rute baru wajib datang bersama baris matriksnya; setiap rute POST baru wajib masuk salah satu daftar pada 
RuteIdempotenTest; setiap kunci baru wajib mengikuti tangga pada docs/PETA-SKEMA.md. Latihan 1 sampai 6 
wajib selesai sebelum Sesi 5. 
1. Bambang: mengubah alamat. Buat PATCH /alamat/{id} lewat BukuAlamat. Tulis uji yang membuktikan 
pesanan lama tidak ikut berubah, dan jelaskan pada deskripsi PR mengapa rute ini tidak memerlukan 
Idempotency-Key. 
2. Rani: melihat satu checkout. Buat GET /checkout/{kode} yang menampilkan sebuah checkout beserta 
pesanan dan tagihannya, hanya untuk pembelinya, dan tambahkan saringan ?status= pada GET /pesanan. 
Buktikan dengan uji bahwa jumlah kueri tidak bertambah seiring jumlah pesanan. 
3. Bagas: kartu stok. Penyesuaian stok oleh penjual belum meninggalkan riwayat. Buat tabel mutasi_stok yang 
hanya dapat ditambah, seperti mutasi_saldo, dan isi dari ketiga jalur perubahan stok: pembelian, 
pengembalian, dan penyesuaian. Tambahkan invarian pada sebuah perintah pemeriksa: stok sekarang sama 
dengan stok awal ditambah jumlah seluruh mutasinya. 
4. Bagas: kunci optimistis untuk nama dan harga. Tambahkan kolom versi pada produk. PATCH /toko-
saya/produk/{id} wajib membawa versi yang dilihat penjual; bila sudah berubah, jawab 409 beserta versi 
terkini. Naikkan versi di dalam pernyataan UPDATE yang sama dengan syaratnya. Bandingkan pada deskripsi PR: 
kapan kunci optimistis lebih cocok daripada perintah relatif. 
5. Dimas: mengganti rahasia gerbang tanpa menolak webhook. Rahasia yang bocor harus dapat diganti tanpa 
menghentikan pembayaran. Buat TandaTanganGateway menerima dua rahasia sekaligus selama masa 
peralihan, LAPAK_GATEWAY_RAHASIA dan LAPAK_GATEWAY_RAHASIA_LAMA. Uji bahwa rahasia lama yang 
dikosongkan tidak pernah cocok dengan tanda tangan apa pun. 
6. Sekar: memangkas kunci idempotensi. Buat php artisan lapak:pangkas-idempotensi yang menghapus 
catatan berumur lebih dari tujuh hari dan jadwalkan setiap dini hari. Tuliskan pada kontrak API bahwa 
pengulangan setelah tujuh hari diperlakukan sebagai permintaan baru, dan jelaskan mengapa untuk checkout 
hal itu tetap aman. 
7. Semua: perbarui koleksi Postman dan dokumen domain. Tambahkan skrip prapermintaan yang mengisi 
Idempotency-Key dengan UUID baru, dan satu permintaan yang menghitung tanda tangan webhook. Ganti 
permintaan PATCH produk yang masih mengirim stok. Isi bagian "Yang disediakan untuk domain lain" pada 
docs/domain/ untuk PenjagaStok, GembokCheckout, MesinStatusPesanan, Alamat::potret(), dan 
middleware idempoten. 
8. Semua: catatan keputusan. Tulis docs/keputusan-sesi.md, paling banyak satu halaman: mengapa stok 
dikurangi saat checkout dan bukan saat dibayar, beserta harga yang dibayar untuk pilihan itu; mengapa 
catatan idempotensi satu transaksi dengan efeknya; dan satu transaksi pada domain Anda yang akan Anda beri 
percobaan ulang saat buntu, beserta alasannya. 
H. TUGAS DAN LUARAN 
H.1 Luaran Tim 
Luaran Bentuk Keterangan 
Tag penutup sesi-4 Menunjuk commit merge PR penutup 
Tiga belas Pull Request Berstatus Merged 
Sesuai peta unggahan, templat terisi, CI hijau. Jumlahnya 
boleh berbeda bila tim Anda tidak menemui kebuntuan, 
atau menemui lebih dari satu. 

Luaran Bentuk Keterangan 
Papan sesi, peta skema, 
aturan bisnis 
docs/sesi/SESI-4.md, 
docs/PETA-SKEMA.md, 
docs/ATURAN-BISNIS.md 
Tangga kunci tim, status terisi, titipan untuk Sesi 5 tertulis 
Skenario balapan Kelas pada app/Balapan/ Minimal satu per domain, seluruhnya lulus pada MySQL 
Tugas CI kedua kualitas-mysql pada .github/workflows/ci.yml Berjalan hijau dan menjadi pemeriksaan wajib 
Kontrak API docs/KONTRAK-API.md Bagian Idempotensi dan Webhook pembayaran 
Rekap kontribusi Keluaran scripts/rekap-sesi.sh 4 Ditempel pada catatan penutup papan sesi 
Laporan sesi PDF, 5 sampai 7 halaman 
Tangga kunci tim beserta alasan urutannya; untuk setiap 
domain, keluaran skenario balapan versi yang melanggar 
dan versi akhir dari mesin sendiri; uji manual dengan rahasia 
ditutupi; dan uraian Langkah 6 serta Langkah 12 dengan 
kata-kata sendiri 
 
H.2 Luaran Tiap Anggota 
Bukti Minimum 
PR kontrak (jendela A) 1, di-merge pada hari pertemuan 
PR implementasi (jendela B) 1, dibuka sebagai draf paling lambat H+2, memuat uji dan skenario balapan 
Keluaran balapan pada deskripsi PR Dua keluaran dari MySQL di mesin sendiri: skenario terhadap kode yang belum dikunci, dan terhadap versi akhir 
Tinjauan 2, dengan komentar yang menunjukkan diff dibandingkan dengan tangga kunci dan skenario penulis dijalankan 
Log penggunaan AI docs/log-ai/<nama>.md diperbarui untuk Sesi 4 
 
PENTING — Pertanyaan demonstrasi sesi ini 
Penguji akan mencabut satu lockForUpdate() dari domain Anda lalu menjalankan skenario Anda. Sebelum hasilnya 
keluar, sebutkan aturan mana yang akan dilanggar, kira-kira seperti apa angkanya, dan mengapa uji Pest Anda tetap 
hijau. Pertanyaan lanjutannya sama untuk semua anggota: pembeli menekan bay ar dua kali dalam selang sepersekian 
detik. Pada baris mana permintaan kedua berhenti menunggu, apa yang ia terima, dan apa yang terjadi bila 
permintaan pertama ternyata gagal? 
 
I. CHECKLIST PENILAIAN MANDIRI 
I.1 Tim 
No Butir Sudah 
1 Setiap anggota menjalankan seluruh uji pada MySQL sebelum cabang pertama dibuat  
2 Tangga kunci disusun bersama dan masuk docs/PETA-SKEMA.md lewat PR pembuka  
3 Cabang yang menumpang cabang rekan disegarkan dengan rebase --onto, tanpa menyelesaikan konflik pada berkas orang lain  

No Butir Sudah 
4 Perbaikan hasil tinjauan dilebur dengan fixup, dan cabang diunggah ulang dengan --force-with-lease  
5 Lima PR implementasi terbuka sebagai draf pada H+2, masing-masing dengan keluaran skenarionya  
6 Temuan lintas domain diperbaiki pemilik berkasnya lewat PR tersendiri  
7 393 uji lulus pada SQLite dan MySQL, sembilan skenario lulus, dan buku besar seimbang pada main  
8 kualitas-mysql menjadi pemeriksaan wajib, tag sesi-4 terpasang, dan Sekar menerima serah terima  
 
I.2 Per Domain 
No Pemilik Butir Sudah 
9 Sekar Catatan kunci idempotensi dan efeknya tersimpan atau batal bersama; ada uji untuk keduanya  
10 Sekar Permintaan gagal tidak meninggalkan catatan, dan kunci yang sama dapat dipakai lagi  
11 Sekar Setiap rute POST tercatat pada tepat satu daftar, dengan alasan tertulis bila tanpa kunci  
12 Sekar lapak:balapan menolak SQLite dan produksi, dan memakai garis mulai bersama  
13 Bambang Baris pengguna dikunci sebelum alamat dihitung; skenario alamat-pertama pernah dilihat merah  
14 Bambang Pemilik dan tanda utama kiriman klien diabaikan; alamat orang lain dijawab 404  
15 Bambang Checkout di atas batas menuntut sesi segar, dan penolakannya tidak mengubah stok  
16 Bagas ambil() mengunci produk urut nomor dan menolak dipanggil di luar transaksi  
17 Bagas Skenario rebutan-stok merah pada versi kontrak dan lulus pada versi akhir  
18 Bagas PATCH produk menolak stok; penyesuaian memakai selisih dan Idempotency-Key  
19 Rani Seluruh checkout satu transaksi; ada uji yang menggagalkan langkah terakhir dan memeriksa tidak ada yang tersisa  
20 Rani Isi keranjang dibaca dengan kunci setelah keranjangnya dikunci  
21 Rani Nama, harga, dan alamat pada pesanan adalah salinan; angka kiriman klien diabaikan  
22 Rani Pembatalan otomatis memeriksa ulang tagihan di bawah gembok dan mengembalikan stok urut nomor produk  
23 Dimas Webhook tanpa tanda tangan sah dijawab 401 yang sama untuk semua sebab, termasuk saat rahasia kosong  
24 Dimas Jumlah dicocokkan dengan tagihan; kabar kembar membukukan uang satu kali  

No Pemilik Butir Sudah 
25 Dimas Pelunasan, status pesanan, dan pemindahan ke escrow satu transaksi; invarian kelima hijau  
 
 

J. PEMECAHAN MASALAH 
J.1 lapak:balapan menolak berjalan 
Terminal 
grep "^DB_" .env 
php artisan config:clear 
 
Sebab: .env masih menunjuk ke SQLite, atau konfigurasi lama masih tersimpan. Perintah itu sengaja menolak 
SQLite, karena hasilnya di sana tidak berarti. 
J.2 Pest gagal tersambung saat dijalankan pada MySQL 
Terminal 
mysql -u lapak -p -h 127.0.0.1 -e "SHOW DATABASES" 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest --filter=KontrakGalat 
 
Sebab menurut pesannya. "Connection refused": layanan MySQL belum berjalan. "Access denied": nama pengguna 
atau kata sandi pada .env salah, atau pengguna lapak belum diberi hak atas lapak_uji. "Unknown database": 
basis data lapak_uji belum dibuat. 
J.3 Skenario serempak melaporkan proses terlambat 
Terminal 
php artisan lapak:balapan serempak 
php artisan lapak:balapan rebutan-stok --proses=6 
 
Sebab: mesin terlalu lambat untuk menyalakan seluruh proses sebelum garis mulai. Tutup aplikasi berat, atau 
kurangi jumlah proses dengan --proses. Selama serempak belum lulus, hasil skenario lain di mesin itu tidak dapat 
dipercaya, baik yang lulus maupun yang melanggar. 
J.4 Skenario saya selalu lulus walau kuncinya sudah saya cabut 
Terminal 
php artisan lapak:balapan <skenario> --proses=12 
php artisan lapak:balapan <skenario> --proses=12 
php artisan lapak:balapan <skenario> --proses=12 
 
Sebab: balapan tidak terjadi setiap kali. Jalankan beberapa kali dan naikkan jumlah proses. Bila tetap lulus, periksa 
apakah periksa() benar-benar membaca keadaan akhir dari basis data, dan apakah aturan yang diperiksanya 
memang dilanggar oleh kode tanpa kunci. 
J.5 Checkout dijawab 422 LP-11 padahal header sudah dikirim 
Terminal 
K=$(openssl rand -hex 16); echo ${#K} 
 
Sebab menurut pesannya. "wajib membawa header": kuncinya lebih pendek dari 16 karakter atau memuat karakter 
selain huruf, angka, garis bawah, dan tanda hubung. "sudah dipakai untuk permintaan yang berbeda": badan 
permintaan tidak persis sama dengan kiriman pertama. Sidik dihitung atas badan mentah, sehingga beda satu spasi 

atau urutan kolom JSON pun dianggap permintaan lain. Klien yang mengulang harus mengirim ulang badan yang 
sama, bukan menyusunnya kembali. 
J.6 Semua webhook dijawab 401 
Terminal 
grep LAPAK_GATEWAY_RAHASIA .env 
php artisan config:clear 
php artisan lapak:simulasi-bayar <kode tagihan> 
 
Sebab: LAPAK_GATEWAY_RAHASIA belum ada di .env, sehingga seluruh webhook ditolak. Itu perilaku yang benar. 
Bila hanya kiriman curl Anda yang ditolak sedangkan lapak:simulasi-bayar diterima, tanda tangan Anda dihitung 
atas teks yang berbeda dari badan yang dikirim: pakai variabel yang sama untuk keduanya, dan pakai printf, bukan 
echo, supaya tidak ada baris baru yang ikut ditandatangani. 
J.7 SQLSTATE[40001] 1213 Deadlock found 
Terminal 
mysql -u root -p -e "SHOW ENGINE INNODB STATUS\G" \ 
  | sed -n '/LATEST DETECTED DEADLOCK/,/WE ROLL BACK/p' 
 
Sebab: dua transaksi mengunci baris yang sama dengan urutan berlawanan. Keluaran perintah itu memuat dua 
pernyataan yang saling menunggu beserta baris yang dipegang dan ditunggu masing-masing, seperti pada Langkah 
12. Bandingkan keduanya dengan tangga pada docs/PETA-SKEMA.md, lalu perbaiki jalur yang menyimpang. Ingat 
bahwa UPDATE dan DELETE juga mengunci baris yang disentuhnya. 
J.8 SQLSTATE[HY000] 1205 Lock wait timeout exceeded 
Terminal 
mysql -u root -p -e "SELECT trx_id, trx_state, trx_started, trx_mysql_thread_id 
  FROM information_schema.innodb_trx" 
 
Sebab: sebuah transaksi memegang kunci lebih lama dari batas tunggu MySQL. Di mesin pengembangan, pelakunya 
hampir selalu jendela klien basis data yang masih membuka transaksi, atau proses PHP yang berhenti di tengah 
dd(). Perintah di atas menampilkan transaksi yang masih terbuka. Tutup jendela itu, atau hentikan utasnya dengan 
KILL <trx_mysql_thread_id>. 
J.9 Rebase berkonflik pada berkas yang tidak pernah saya sentuh 
Terminal 
git rebase --abort 
git log --oneline --format="%h %an: %s" origin/main..HEAD 
git rebase --onto origin/main <hash commit terakhir milik rekan> 
 
Sebab: cabang Anda dibuat di atas cabang rekan yang riwayatnya kemudian ditulis ulang, seperti pada Langkah 6. 
Perintah kedua menampilkan commit siapa saja yang ada di cabang Anda. Commit terakhir yang bukan milik Anda 
adalah dasar lama. 
J.10 Hijau pada SQLite, merah pada MySQL 
Terminal 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest --filter="<nama uji>" 

Sebab yang ditemui saat modul ini disusun ada dua. Pertama, urutan kunci pada kolom JSON: MySQL menyimpan 
objek JSON dengan urutan kuncinya sendiri, sehingga uji yang membandingkan alamat_kirim sebagai teks, atau 
dengan toBe() terhadap larik berurutan, dapat merah. Bandingkan isinya per kunci. Kedua, tanda kutip nama tabel: 
SQLite menulis "produk", MySQL menulis nama yang sama di antara tanda petik terbalik. Uji yang mencocokkan 
teks kueri harus menerima keduanya. 
J.11 Checkout dijawab 429 saat sedang mencoba-coba 
Terminal 
curl -s -D - -o /dev/null -X POST $B/checkout -H "Authorization: Bearer $ANDI" | grep -i retry-after 
 
Sebab: pembatas laju transaksi mengizinkan sepuluh permintaan per menit per sesi, termasuk permintaan yang 
ditolak validasi. Header Retry-After menyebut berapa detik lagi jatahnya pulih. Sesi lain milik akun yang sama 
memiliki jatah sendiri. 
J.12 PR bertumpuk masih menunjuk ke cabang yang sudah dihapus 
Terminal 
gh pr view <nomor> --json baseRefName 
gh pr edit <nomor> --base main 
 
Sebab: PR dibuka dengan dasar cabang rekan, dan dasar itu tidak berpindah sendiri setelah cabangnya di-merge. 
Perintah kedua memindahkannya ke main. Setelah itu segarkan cabang seperti pada J.9. 
K. REFERENSI 
• Oracle. MySQL 8.0 Reference Manual: InnoDB Transaction Isolation Levels; Locking Reads; Deadlocks in 
InnoDB; How to Minimize and Handle Deadlocks. https://dev.mysql.com/doc/refman/8.0/en/innodb-
transaction-model.html 
• Laravel. (2026). Laravel Documentation: Database (Transactions, Deadlocks), Queries (Pessimistic Locking), 
Rate Limiting, Task Scheduling. https://laravel.com/docs 
• Fielding, R., Nottingham, M., dan Reschke, J. (2022). RFC 9110: HTTP Semantics, bagian 9.2.2 Idempotent 
Methods. https://www.rfc-editor.org/rfc/rfc9110 
• Jena, J., dan Dalal, S. The Idempotency-Key HTTP Header Field. Internet-Draft, IETF HTTPAPI Working Group. 
https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/ 
• Krawczyk, H., Bellare, M., dan Canetti, R. (1997). RFC 2104: HMAC: Keyed-Hashing for Message Authentication. 
https://www.rfc-editor.org/rfc/rfc2104 
• Stripe. Idempotent requests; Receive Stripe events in your webhook endpoint (verifikasi tanda tangan). 
https://docs.stripe.com/api/idempotent_requests 
• OWASP. (2023). OWASP API Security Top 10: API6 Unrestricted Access to Sensitive Business Flows. 
https://owasp.org/API-Security/editions/2023/en/0x11-t10/ 
• OWASP. Transaction Authorization Cheat Sheet. 
https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html 
• Berenson, H., Bernstein, P., Gray, J., Melton, J., O'Neil, E., dan O'Neil, P. (1995). A Critique of ANSI SQL Isolation 
Levels. Proceedings of ACM SIGMOD, 1-10. 
• Kleppmann, M. (2017). Designing Data-Intensive Applications, Bab 7: Transactions. O'Reilly Media. 

• Git. git-rebase (opsi --onto dan --autosquash); git-commit (opsi --fixup). https://git-scm.com/docs/git-
rebase 
• GitHub Docs. Use containerized services (kontainer layanan pada GitHub Actions; contohnya memakai Redis 
dan PostgreSQL, polanya sama untuk MySQL). https://docs.github.com/en/actions/tutorials/use-
containerized-services/create-redis-service-containers 
• GitHub Docs. Merging a pull request (termasuk pengalihan otomatis PR yang menumpang cabang yang 
dihapus);