<?php 
  
declare(strict_types=1); 
  
namespace App\Balapan; 
  
use App\Exceptions\ProdukTidakDapatDibeli; 
use App\Models\Kategori; 
use App\Models\Produk; 
use App\Models\Toko; 
use App\Services\PenjagaStok; 
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Str; 
  
/** 
 * Dua belas pembeli berebut lima barang terakhir. Masing-masing membeli satu. 
 * Aturannya (LP-4): yang terbeli ditambah yang tersisa harus sama dengan stok awal. 
 */ 
final class RebutanStok implements SkenarioBalapan 
{ 
    private const STOK_AWAL = 5; 
  
    public function __construct(private readonly PenjagaStok $penjaga) {} 
  
    public function keterangan(): string 
    { 
        return 'Dua belas pembeli berebut '.self::STOK_AWAL.' barang: terjual tepat sebanyak itu'; 
    } 
  
    public function proses(): int 
    { 
        return 12; 
    } 
  
    public function siapkan(): array 
    { 

        $akhiran = Str::lower(Str::random(8)); 
        $toko = Toko::factory()->create(['slug' => "toko-{$akhiran}"]); 
        $produk = Produk::factory()->for($toko)->for(Kategori::query()->firstOrFail())->create([ 
            'slug' => "produk-{$akhiran}", 'stok' => self::STOK_AWAL, 
        ]); 
  
        return ['produk' => $produk->id]; 
    } 
  
    public function jalankan(array $konteks, int $nomor): string 
    { 
        $id = $konteks['produk']; 
  
        try { 
            DB::transaction(function () use ($id): void { 
                $this->penjaga->kurangi($this->penjaga->ambil([$id])[$id], 1); 
            }); 
        } catch (ProdukTidakDapatDibeli) { 
            return 'ditolak: stok habis'; 
        } 
  
        return 'terbeli'; 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $terbeli = count(array_filter($hasil, fn (string $h) => $h === 'terbeli')); 
        $sisa = Produk::query()->findOrFail($konteks['produk'])->stok; 
        $awal = self::STOK_AWAL; 
  
        $pelanggaran = []; 
        if ($terbeli > $awal) { 
            $pelanggaran[] = "{$terbeli} pembeli berhasil, padahal barangnya hanya {$awal}."; 
        } 
        if ($terbeli + $sisa !== $awal) { 
            $pelanggaran[] = "Terbeli {$terbeli} ditambah sisa {$sisa} bukan stok awal {$awal}."; 
        } 
  
        return $pelanggaran; 
    } 
} 
 
Terminal — Bagas, PenjagaStok masih versi kontrak 
git add app/Balapan/RebutanStok.php 
git commit -m "test: skenario balapan rebutan-stok" 
php artisan migrate:fresh --seed 
php artisan lapak:balapan rebutan-stok 
 
Keluaran — PenjagaStok versi kontrak pada MySQL 
rebutan-stok: Dua belas pembeli berebut 5 barang: terjual tepat sebanyak itu 
+---------+---------------+ 
| Hasil   | Jumlah proses | 
+---------+---------------+ 
| terbeli | 12            | 
+---------+---------------+ 
PELANGGARAN: 12 pembeli berhasil, padahal barangnya hanya 5. 
PELANGGARAN: Terbeli 12 ditambah sisa 4 bukan stok awal 5. 
 
Dua belas pembeli berhasil membeli lima barang. Stok akhirnya bukan nol dan bukan negatif, melainkan empat, 
karena hampir semua proses membaca angka 5 lalu menulis angka 4. Pada dua percobaan berikutnya sisanya tiga. 
Inilah kode yang pada Langkah 4 lulus 277 uji. 
9.2 Perbaikannya dua baris. 
app/Services/PenjagaStok.php — perbaikan pada commit kedua 
 use Illuminate\Support\Collection; 
+use Illuminate\Support\Facades\DB; 
 use InvalidArgumentException; 
+use LogicException; 

   ... 
     /** 
-     * Produk yang akan dibeli, beserta tokonya, berkunci nomor produk. 
+     * Produk yang akan dibeli, beserta tokonya, berkunci nomor produk. Barisnya DIKUNCI sampai 
+     * transaksi pemanggil selesai. 
      * 
   ... 
     { 
-        // BELUM AMAN bila dua pembeli datang bersamaan: baris dibaca tanpa dikunci. 
-        // Versi kontrak ini benar untuk satu pembeli pada satu waktu. Dikunci pada jendela B. 
+        // Kunci baris hanya bertahan sampai transaksi selesai. Di luar transaksi, kunci dilepas 
+        // begitu SELECT ini selesai dan tidak melindungi apa pun. 
+        if (DB::transactionLevel() === 0) { 
+            throw new LogicException('PenjagaStok::ambil() harus di dalam DB::transaction().'); 
+        } 
+ 
+        // lockForUpdate(): pembeli lain yang menginginkan baris yang sama MENUNGGU di sini sampai 
+        // transaksi ini selesai, lalu membaca stok yang sudah berkurang. 
+        // orderBy('id'): semua pembeli mengunci dengan urutan yang sama, apa pun urutan isi 
+        // keranjangnya, sehingga tidak ada dua transaksi yang saling menunggu selamanya. 
         return Produk::query() 
   ... 
             ->whereIn('id', $idProduk) 
+            ->orderBy('id') 
+            ->lockForUpdate() 
             ->get() 
 
Baris Kegunaan 
->lockForUpdate() Pembeli lain yang menginginkan baris yang sama menunggu sampai transaksi ini selesai, lalu membaca stok yang sudah berkurang 
->orderBy('id') 
Semua pembeli mengunci produk dengan urutan yang sama, apa pun urutan isi 
keranjangnya. Tanpa ini, dua keranjang berisi produk yang sama dengan urutan 
terbalik dapat saling menunggu. 
Pemeriksaan transactionLevel() 
Kunci hanya hidup di dalam transaksi. Pemanggil yang lupa membukanya 
ditolak dengan pesan yang menjelaskan sebabnya, bukan dibiarkan memegang 
kunci yang langsung lepas. 
 
tests/Feature/Katalog/PenjagaStokTest.php — uji yang ditambahkan 
 use App\Models\Toko; 
 use App\Services\PenjagaStok; 
+use Illuminate\Support\Facades\DB; 
  
 beforeEach(function () { 
   ... 
         ->and($produk[$dariTokoTutup->id]->sedangTampil())->toBeFalse(); 
 }); 
+ 
+it('menolak dipanggil di luar transaksi, karena kuncinya tidak akan bertahan', function () { 
+    // Setiap uji berjalan di dalam transaksi milik RefreshDatabase. Transaksi itu ditutup dulu 
+    // untuk meniru pemanggil yang lupa membuka DB::transaction(), lalu dibuka kembali. 
+    DB::rollBack(); 
+ 
+    try { 
+        expect(fn () => $this->penjaga->ambil([1]))->toThrow(LogicException::class); 
+    } finally { 
+        DB::beginTransaction(); 
+    } 
+}); 
 
Keluaran — setelah perbaikan 
rebutan-stok: Dua belas pembeli berebut 5 barang: terjual tepat sebanyak itu 
+---------------------+---------------+ 
| Hasil               | Jumlah proses | 
+---------------------+---------------+ 
| ditolak: stok habis | 7             | 
| terbeli             | 5             | 

+---------------------+---------------+ 
LULUS: 12 proses serentak, tidak ada aturan yang dilanggar. 
 
Terminal — Bagas 
git add app/Services/PenjagaStok.php tests/Feature/Katalog/PenjagaStokTest.php 
git commit -m "fix: kunci baris produk sebelum membaca stok (LP-4)" 
 
9.3 Lubang kedua: stok mutlak dari layar penjual. Saat menulis tabel pada 9.2, Bagas bertanya kepada dirinya 
sendiri: siapa lagi yang menulis kolom stok? Jawabannya ada di domainnya sendiri. PATCH /toko-
saya/produk/{id} sejak Sesi 2 menerima stok sebagai angka mutlak. Ia mencobanya pada main yang sudah 
memuat checkout, dengan dua terminal dan tanpa alat balapan apa pun. Variabel $B dan $J serta kunci $SRI dan 
$ANDI disiapkan seperti pada Langkah 13. 
Terminal — Bu Sri melihat stok, Andi membeli tiga, Bu Sri menambah lima 
curl -s $B/toko-saya/produk -H "Authorization: Bearer $SRI" \ 
  | jq -c '.data[] | select(.id==2) | {id, nama, stok}' 
  
curl -s -X PUT $B/keranjang/butir/2 -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -d '{"kuantitas":3}' > /dev/null 
curl -s -X POST $B/checkout -H "Authorization: Bearer $ANDI" -H "$J" \ 
  -H "Idempotency-Key: $(openssl rand -hex 16)" -d '{"alamat_id":1}' \ 
  | jq -c '{checkout: .sukses, butir: .data.pesanan[0].butir[0] | {nama, kuantitas}}' 
  
curl -s -X PATCH $B/toko-saya/produk/2 -H "Authorization: Bearer $SRI" -H "$J" \ 
  -d '{"stok":30}' | jq -c '.data | {id, stok}' 
 
Keluaran — pada main sebelum B3 
{"id":2,"nama":"Kain Batik Cap Sogan 2 Meter","stok":25} 
{"checkout":true,"butir":{"nama":"Kain Batik Cap Sogan 2 Meter","kuantitas":3}} 
{"id":2,"stok":30} 
 
Bu Sri melihat 25, menerima kiriman 5 kain, lalu menulis 30. Di antaranya tiga kain terjual. Stok yang benar 27; basis 
data mencatat 30, dan tiga kain yang tidak ada kelak terjual kepada pembeli berikutnya. Tidak ada dua permintaan 
yang berjalan bersamaan di sini, sehingga tidak ada kunci yang dapat menolong (D.5). 
9.4 Penyesuaian relatif lewat PenjagaStok. 
app/Services/PenjagaStok.php — metode yang ditambahkan 
    /** 
     * Dipakai penjual. Perubahannya RELATIF ("tambah 5"), tidak pernah mutlak ("jadikan 15"). 
     * Angka yang dilihat penjual di layarnya mungkin sudah usang ketika ia menekan simpan, dan 
     * kunci baris tidak dapat melindungi pembacaan yang terjadi di layar orang. 
     * 
     * @throws PenyesuaianStokDitolak 409 LP-4 bila hasilnya negatif atau melewati batas 
     */ 
    public function sesuaikan(int $idProduk, int $selisih): Produk 
    { 
        if ($selisih === 0) { 
            throw new InvalidArgumentException('Selisih tidak boleh nol.'); 
        } 
  
        return DB::transaction(function () use ($idProduk, $selisih): Produk { 
            // Baris yang sama dengan yang dikunci ambil(): penjual dan pembeli saling menunggu. 
            $produk = Produk::query()->lockForUpdate()->findOrFail($idProduk); 
            $baru = $produk->stok + $selisih; 
  
            if ($baru < 0) { 
                throw PenyesuaianStokDitolak::akanNegatif( 
                    $produk->id, $produk->nama, $produk->stok, $selisih, 
                ); 
            } 
  
            if ($baru > self::MAKSIMUM) { 

                throw PenyesuaianStokDitolak::melewatiBatas( 
                    $produk->id, $produk->nama, $produk->stok, self::MAKSIMUM, 
                ); 
            } 
  
            $produk->stok = $baru; 
            $produk->save(); 
  
            return $produk; 
        }); 
    } 
 
Tambahkan pula konstanta public const MAKSIMUM = 100_000; di awal kelas dan baris use 
App\Exceptions\PenyesuaianStokDitolak;. Berkas utuhnya ada pada berkas pendamping. 
app/Exceptions/PenyesuaianStokDitolak.php 
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