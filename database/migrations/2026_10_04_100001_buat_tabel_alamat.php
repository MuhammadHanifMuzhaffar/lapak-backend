<?php 
  
use Illuminate\Database\Migrations\Migration; 
use Illuminate\Database\Schema\Blueprint; 
use Illuminate\Support\Facades\Schema; 
  
return new class extends Migration 
{ 
    public function up(): void 
    { 
        Schema::create('alamat', function (Blueprint $tabel) { 
            $tabel->id(); 
            $tabel->foreignId('pengguna_id')->constrained('pengguna')->cascadeOnDelete(); 
            $tabel->string('label', 30); 
            $tabel->string('nama_penerima', 80); 
            $tabel->string('telepon', 20); 
            $tabel->string('jalan', 200); 
            $tabel->string('kota', 60); 
            $tabel->string('kode_pos', 5); 
            // Tepat satu alamat utama per pengguna. Basis data tidak dapat menjaminnya dengan 
            // indeks yang berlaku di MySQL maupun SQLite, maka BukuAlamat yang menjaganya. 
            $tabel->boolean('utama')->default(false); 
            $tabel->timestamps(); 
  
            $tabel->index(['pengguna_id', 'utama']); 
        }); 
    } 
  
    public function down(): void 
    { 
        Schema::dropIfExists('alamat'); 
    } 
}; 
 
Aturan "tepat satu alamat utama per pengguna" tidak dititipkan kepada basis data. Indeks unik atas pengguna_id 
dan utama akan melarang dua alamat yang sama-sama bukan utama. Yang menjaganya adalah BukuAlamat pada 
Langkah 7. 
app/Models/Alamat.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Models; 
  
use Database\Factories\AlamatFactory; 
use Illuminate\Database\Eloquent\Builder; 
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model; 
use Illuminate\Database\Eloquent\Relations\BelongsTo; 
  
final class Alamat extends Model 
{ 
    /** @use HasFactory<AlamatFactory> */ 
    use HasFactory; 
  
    protected $table = 'alamat'; 
  
    // pengguna_id dan utama TIDAK ada di sini: pemilik ditentukan sesi, 
    // dan alamat utama hanya diatur BukuAlamat. 
    protected $fillable = ['label', 'nama_penerima', 'telepon', 'jalan', 'kota', 'kode_pos']; 

    protected function casts(): array 
    { 
        return ['utama' => 'boolean']; 
    } 
  
    /** @return BelongsTo<Pengguna, $this> */ 
    public function pengguna(): BelongsTo 
    { 
        return $this->belongsTo(Pengguna::class, 'pengguna_id'); 
    } 
  
    /** 
     * Seluruh pintu alamat berangkat dari scope ini. Nomor alamat milik orang lain dijawab 404. 
     * 
     * @param  Builder<Alamat>  $kueri 
     */ 
    public function scopeMilik(Builder $kueri, Pengguna $pengguna): void 
    { 
        $kueri->where('pengguna_id', $pengguna->id); 
    } 
  
    /** 
     * Salinan alamat untuk DISIMPAN pada pesanan. Pesanan tidak menunjuk ke baris alamat: 
     * pembeli boleh mengubah atau menghapus alamatnya besok, sedangkan paket hari ini tetap 
     * harus sampai ke alamat yang tertulis saat ia membayar. 
     * 
     * @return array<string, string> nama_penerima, telepon, jalan, kota, kode_pos 
     */ 
    public function potret(): array 
    { 
        return $this->only(['nama_penerima', 'telepon', 'jalan', 'kota', 'kode_pos']); 
    } 
} 
 
potret() adalah kontrak Bambang kepada Rani. Checkout tidak membaca kolom alamat satu per satu; ia 
memanggil potret() dan menyimpan hasilnya. Bila besok alamat mendapat kolom kecamatan, hanya metode ini 
yang berubah. 
app/Http/Resources/AlamatResource.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Resources; 
  
use App\Models\Alamat; 
use Illuminate\Http\Request; 
use Illuminate\Http\Resources\Json\JsonResource; 
  
/** 
 * @mixin Alamat 
 */ 
final class AlamatResource extends JsonResource 
{ 
    public function toArray(Request $request): array 
    { 
        return [ 
            'id' => $this->id, 
            'label' => $this->label, 
            'utama' => $this->utama, 
            ...$this->potret(), 
        ]; 
    } 
} 
 
app/Http/Controllers/AlamatController.php — versi kontrak 
<?php 
  
declare(strict_types=1); 
  

namespace App\Http\Controllers; 
  
use App\Http\Resources\AlamatResource; 
use App\Models\Alamat; 
use Illuminate\Http\JsonResponse; 
use Illuminate\Http\Request; 
  
final class AlamatController extends Controller 
{ 
    /** GET /alamat: alamat milik pengguna sesi, alamat utama lebih dulu. */ 
    public function index(Request $request): JsonResponse 
    { 
        $alamat = Alamat::query() 
            ->milik($request->user()) 
            ->orderByDesc('utama') 
            ->orderBy('id') 
            ->get(); 
  
        return $this->sukses(AlamatResource::collection($alamat)); 
    } 
} 
 
routes/api/identitas.php — perubahan A2 
 <?php 
  
+use App\Http\Controllers\AlamatController; 
 use App\Http\Controllers\MasukController; 
 use App\Http\Controllers\PeranPenggunaController; 
   ... 
         }); 
     }); 
+ 
+// Alamat kirim milik pengguna sesi. Izin pesanan.buat lahir bersama rute ini. 
+Route::middleware(['auth:sesi', 'izin:pesanan.buat']) 
+    ->prefix('alamat') 
+    ->name('alamat.') 
+    ->whereNumber('id') 
+    ->group(function (): void { 
+        Route::get('/', [AlamatController::class, 'index'])->name('index'); 
+    }); 
 
tests/Feature/Sistem/matriks/identitas.php — perubahan A2 
     'PUT api/v1/admin/pengguna/{id}/peran/{peran}' => ['admin'], 
     'DELETE api/v1/admin/pengguna/{id}/peran/{peran}' => ['admin'], 
+ 
+    'GET api/v1/alamat' => ['pembeli'], 
 ]; 
 
AlamatFactory, dengan state cadangan() untuk alamat kedua, dan AlamatSeeder, yang memberi alamat kepada 
Andi, Dewi, dan Bu Sri, tersedia pada berkas pendamping. Seeder itu didaftarkan setelah PenggunaSeeder: 
database/seeders/DatabaseSeeder.php — satu baris ditambahkan 
             PeranIzinSeeder::class, 
             PenggunaSeeder::class, 
+            AlamatSeeder::class, 
             KatalogSeeder::class, 
             DompetSeeder::class, 
 
3.3 Penjaga transaksi dan batas laju. 
app/Services/PenjagaTransaksi.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  

use App\Domain\Uang; 
use App\Exceptions\SesiTidakSegar; 
use App\Models\Sesi; 
  
/** 
 * LP-15 untuk belanja: transaksi bernilai besar menuntut sesi segar. 
 * 
 * Tidak dapat dipasang sebagai middleware, karena nilainya baru diketahui setelah server 
 * menghitung total dari basis data. Checkout memanggil kelas ini di dalam transaksinya, 
 * setelah total dihitung dan sebelum apa pun di-commit. 
 */ 
final class PenjagaTransaksi 
{ 
    public function pastikanLayak(Sesi $sesi, Uang $total): void 
    { 
        $batas = Uang::dari(config('lapak.transaksi.batas_segar_rupiah')); 
  
        if ($total->lebihDari($batas) && ! $sesi->segar()) { 
            throw new SesiTidakSegar; 
        } 
    } 
} 
 
Pada Sesi 2 dan 3, sesi segar dipasang sebagai middleware sesi.segar pada rute. Di sini tidak dapat: apakah 
sebuah checkout bernilai besar baru diketahui setelah server menghitung totalnya dari basis data. Maka 
pemeriksaannya menjadi kelas yang dipanggil dari dalam transaksi checkout. Bila ia melempar galat, seluruh 
transaksi batal dan stok tidak tersentuh. 
config/lapak.php — blok yang ditambahkan 
     ], 
  
+    'transaksi' => [ 
+        // LP-15: checkout di atas nilai ini menuntut sesi segar. 
+        'batas_segar_rupiah' => (int) env('LAPAK_BATAS_SEGAR_RUPIAH', 2_000_000), 
+        // Permintaan per menit, per sesi, untuk pintu yang mengubah uang atau stok. 
+        'laju_per_menit' => 10, 
+        'alamat_maksimum' => 5, 
+    ], 
+ 
     // LP-14 dan LP-15: umur kunci sesi. 
     'sesi' => [ 
 
app/Providers/AppServiceProvider.php — perubahan A2 
  
 use App\Models\Pengguna; 
+use App\Models\Sesi; 
 use App\Services\PemeriksaSesi; 
 use Illuminate\Cache\RateLimiting\Limit; 
   ... 
         ]); 
  
+        // Pintu yang mengubah uang atau stok dibatasi per SESI, bukan per IP. Satu kunci curian 
+        // tidak dapat dipakai menghujani checkout, dan pembeli lain di jaringan yang sama 
+        // (kampus, kantor) tidak ikut terhambat. 
+        RateLimiter::for('transaksi', fn (Request $request) => Limit::perMinute( 
+            config('lapak.transaksi.laju_per_menit'), 
+        )->by('transaksi:'.Sesi::dariPermintaan($request)->pengenal)); 
+ 
         RateLimiter::for('konfirmasi', fn (Request $request) => Limit::perMinute( 
             config('lapak.laju.konfirmasi'), 
 
Pembatas laju pada POST /masuk di Sesi 2 dihitung per surel dan per alamat IP, karena pemanggilnya belum 
dikenal. Pembatas transaksi dihitung per sesi. Satu kunci curian tidak dapat dipakai menghujani checkout, dan 
pembeli lain di jaringan kampus yang sama tidak ikut terhambat. 
tests/Feature/Identitas/PenjagaTransaksiTest.php 
<?php 

use App\Domain\Uang; 
use App\Exceptions\SesiTidakSegar; 
use App\Models\Pengguna; 
use App\Models\Sesi; 
use App\Services\PenerbitSesi; 
use App\Services\PenjagaTransaksi; 
use Illuminate\Support\Facades\Route; 
  
beforeEach(function () { 
    config(['lapak.transaksi.batas_segar_rupiah' => 2_000_000]); 
  
    $this->penjaga = app(PenjagaTransaksi::class); 
    $this->sesi = function (bool $segar): Sesi { 
        [$sesi] = app(PenerbitSesi::class)->terbitkan(Pengguna::factory()->create(), 'Laptop'); 
  
        if (! $segar) { 
            $sesi->forceFill(['dikonfirmasi_pada' => now()->subHour()])->save(); 
        } 
  
        return $sesi; 
    }; 
}); 
  
it('meloloskan transaksi kecil pada sesi yang tidak segar', function (int $rupiah) { 
    $this->penjaga->pastikanLayak(($this->sesi)(false), Uang::dari($rupiah)); 
})->with([185_000, 2_000_000])->throwsNoExceptions(); 
  
it('menuntut sesi segar untuk transaksi di atas batas (LP-15)', function () { 
    $this->penjaga->pastikanLayak(($this->sesi)(false), Uang::dari(2_000_001)); 
})->throws(SesiTidakSegar::class); 
  
it('meloloskan transaksi besar pada sesi yang segar', function () { 
    $this->penjaga->pastikanLayak(($this->sesi)(true), Uang::dari(15_000_000)); 
})->throwsNoExceptions(); 
  
it('membatasi laju pintu transaksi per sesi, bukan per alamat IP', function () { 
    config(['lapak.transaksi.laju_per_menit' => 3]); 
    Route::middleware(['api', 'auth:sesi', 'throttle:transaksi']) 
        ->post('/api/_uji/transaksi', fn () => ['ok' => true]); 
  
    $andi = Pengguna::factory()->create(); 
    $this->sebagai($andi); 
  
    foreach (range(1, 3) as $ke) { 
        $this->postJson('/api/_uji/transaksi')->assertOk(); 
    } 
    $this->postJson('/api/_uji/transaksi') 
        ->assertStatus(429) 
        ->assertJsonPath('galat.kode', 'TERLALU_SERING') 
        ->assertHeader('Retry-After'); 
  
    // Perangkat lain milik orang yang sama, dari alamat IP yang sama, memiliki jatah sendiri. 
    $this->sebagai($andi)->postJson('/api/_uji/transaksi')->assertOk(); 
}); 
 
tests/Feature/Identitas/AlamatTest.php — versi kontrak, dua uji terpenting 
it('mendaftar alamat milik sendiri saja, alamat utama lebih dulu', function () { 
    Alamat::factory()->cadangan()->for($this->andi, 'pengguna') 
        ->create(['jalan' => 'Jl. Kantor 1']); 
    Alamat::factory()->for($this->andi, 'pengguna')->create(['jalan' => 'Jl. Rumah 2']); 
    Alamat::factory()->create(['jalan' => 'Jl. Orang Lain 3']); 
  
    $this->sebagai($this->andi)->getJson('/api/v1/alamat') 
        ->assertOk() 
        ->assertJsonCount(2, 'data') 
        ->assertJsonPath('data.0.jalan', 'Jl. Rumah 2') 
        ->assertJsonPath('data.0.utama', true) 
        ->assertJsonPath('data.1.jalan', 'Jl. Kantor 1') 
        ->assertJsonMissingPath('data.0.pengguna_id'); 
}); 
  
it('memotret alamat tanpa membawa pengenal dan label', function () { 
    $alamat = Alamat::factory()->for($this->andi, 'pengguna')->create([ 
        'nama_penerima' => 'Andi Nugroho', 'telepon' => '081200000008', 

        'jalan' => 'Jl. Slamet Riyadi No. 12', 'kota' => 'Surakarta', 'kode_pos' => '57111', 
    ]); 
  
    expect($alamat->potret())->toBe([ 
        'nama_penerima' => 'Andi Nugroho', 
        'telepon' => '081200000008', 
        'jalan' => 'Jl. Slamet Riyadi No. 12', 
        'kota' => 'Surakarta', 
        'kode_pos' => '57111', 
    ]); 
}); 
 
Terminal — Bambang 
php artisan migrate:fresh --seed 
./vendor/bin/pint 
./vendor/bin/pest 
git add app/Enums tests/Feature/Sistem/IzinTest.php tests/Feature/Identitas/SesiTest.php \ 
        docs/KONTRAK-API.md 
git commit -m "feat: izin pesanan.buat untuk pembeli" 
git add database/migrations/2026_10_04_100001_buat_tabel_alamat.php app/Models/Alamat.php \ 
        database/factories/AlamatFactory.php app/Http/Resources/AlamatResource.php \ 
        app/Http/Controllers/AlamatController.php routes/api/identitas.php \ 
        tests/Feature/Sistem/matriks/identitas.php database/seeders \ 
        tests/Feature/Identitas/AlamatTest.php 
git commit -m "feat: tabel alamat, potret alamat, dan daftar alamat saya" 
git add app/Services/PenjagaTransaksi.php config/lapak.php app/Providers/AppServiceProvider.php \ 
        tests/Feature/Identitas/PenjagaTransaksiTest.php 
git commit -m "feat: penjaga transaksi dan pembatas laju per sesi (LP-15)" 
git push -u origin sesi-4/kontrak-alamat-bambang 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 281 uji lulus. Commit pertama, bila diuji sendirian, merah pada IzinTerpakaiTest: 
izinnya sudah ada, rutenya belum. Itu sebabnya ketiga commit diunggah sebagai satu PR. 
Langkah 4 — A3: Penjaga Stok, Versi Kontrak (Bagas) 
Cabang: sesi-4/kontrak-stok-bagas. Kontrak terkecil pada sesi ini, dan yang paling perlu dibaca komentarnya. 
app/Services/PenjagaStok.php — versi kontrak 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Exceptions\ProdukTidakDapatDibeli; 
use App\Models\Produk; 
use Illuminate\Support\Collection; 
use InvalidArgumentException; 
  
/** 
 * Satu-satunya pintu perubahan stok oleh transaksi (LP-4), seperti BukuBesar bagi saldo. 
 * Checkout tidak pernah menulis kolom stok sendiri: ia mengambil produk lewat ambil(), 
 * lalu mengurangi lewat kurangi(). 
 */ 
final class PenjagaStok 
{ 
    /** 
     * Produk yang akan dibeli, beserta tokonya, berkunci nomor produk. 
     * 
     * @param  list<int>  $idProduk 
     * @return Collection<int, Produk> 
     */ 
    public function ambil(array $idProduk): Collection 
    { 
        // BELUM AMAN bila dua pembeli datang bersamaan: baris dibaca tanpa dikunci. 
        // Versi kontrak ini benar untuk satu pembeli pada satu waktu. Dikunci pada jendela B. 
        return Produk::query() 
            ->with('toko') 
            ->whereIn('id', $idProduk) 
            ->get() 
            ->keyBy('id'); 

    } 
  
    /** 
     * @param  Produk  $produk  hasil ambil(), bukan hasil kueri lain 
     * 
     * @throws ProdukTidakDapatDibeli 409 LP-4 bila stok tidak mencukupi 
     */ 
    public function kurangi(Produk $produk, int $kuantitas): void 
    { 
        if ($kuantitas < 1) { 
            throw new InvalidArgumentException('Kuantitas minimal 1.'); 
        } 
  
        if ($produk->stok < $kuantitas) { 
            throw ProdukTidakDapatDibeli::stokKurang( 
                $produk->id, $produk->nama, $kuantitas, $produk->stok, 
            ); 
        } 
  
        $produk->stok -= $kuantitas; 
        $produk->save(); 
    } 
  
    /** Dipakai pembatalan. Menambah tidak memerlukan keputusan, maka cukup satu UPDATE. */ 
    public function kembalikan(int $idProduk, int $kuantitas): void 
    { 
        if ($kuantitas < 1) { 
            throw new InvalidArgumentException('Kuantitas minimal 1.'); 
        } 
  
        Produk::query()->whereKey($idProduk)->increment('stok', $kuantitas); 
    } 
} 
 
PenjagaStok bagi stok sama seperti BukuBesar bagi saldo: satu-satunya kelas yang menulis kolom itu. Tiga 
metodenya adalah seluruh kontrak Bagas kepada Rani. 
Metode Dipakai oleh Janji 
ambil(array $idProduk) Checkout Mengembalikan produk beserta tokonya, berkunci nomor produk 
kurangi(Produk $produk, int 
$kuantitas) Checkout Mengurangi stok, atau melempar 409 LP-4 bila kurang 
kembalikan(int $idProduk, int 
$kuantitas) Pembatalan Menambah stok dengan satu UPDATE 
 
ambil() pada versi ini membaca tanpa mengunci, dan komentarnya mengatakan demikian. Bagas sengaja 
mengunggahnya begitu. Tanda tangan ketiga metode sudah tetap, sehingga Rani dapat menulis checkout 
terhadapnya hari ini; yang berubah pada jendela B hanyalah isi ambil(). Kontrak yang jujur tentang lubangnya 
lebih berguna daripada kontrak yang terlambat dua hari. 
kembalikan() berbeda sifat. Menambah stok tidak memerlukan keputusan apa pun, sehingga cukup satu 
pernyataan UPDATE produk SET stok = stok + ?. Basis data menjalankan pernyataan seperti itu secara atomik 
tanpa bantuan kunci dari kita. 
app/Models/Produk.php — metode yang ditambahkan 
     } 
  
+    /** Syarat yang sama dengan scopeTampil, untuk baris yang sudah di tangan beserta tokonya. */ 
+    public function sedangTampil(): bool 
+    { 
+        return $this->status === StatusProduk::Aktif 
+            && $this->toko->status === StatusToko::Aktif; 
+    } 
+ 
     /** 

      * LP-4: produk tampil hanya bila berstatus aktif DAN tokonya aktif. 
 
scopeTampil dari Sesi 1 menyaring di tingkat kueri. Checkout tidak dapat memakainya, karena produknya sudah 
diambil lewat PenjagaStok. sedangTampil() menyatakan syarat yang sama untuk baris yang sudah di tangan, dan 
sebuah uji memastikan keduanya tidak pernah berbeda pendapat. 
tests/Feature/Katalog/PenjagaStokTest.php — versi kontrak, empat uji pertama 
<?php 
  
use App\Enums\StatusProduk; 
use App\Enums\StatusToko; 
use App\Exceptions\ProdukTidakDapatDibeli; 
use App\Models\Produk; 
use App\Models\Toko; 
use App\Services\PenjagaStok; 
  
beforeEach(function () { 
    $this->penjaga = app(PenjagaStok::class); 
    $this->kain = Produk::factory()->create(['nama' => 'Kain Batik Cap', 'stok' => 5]); 
}); 
  
it('mengambil produk berkunci nomornya, beserta tokonya', function () { 
    $lain = Produk::factory()->create(); 
    Produk::factory()->create(); 
  
    $produk = $this->penjaga->ambil([$lain->id, $this->kain->id, 999_999]); 
  
    expect($produk->keys()->sort()->values()->all())->toBe([$this->kain->id, $lain->id]) 
        ->and($produk[$this->kain->id]->relationLoaded('toko'))->toBeTrue(); 
}); 
  
it('mengurangi stok sebanyak yang dibeli', function () { 
    $this->penjaga->kurangi($this->penjaga->ambil([$this->kain->id])[$this->kain->id], 2); 
  
    expect($this->kain->refresh()->stok)->toBe(3); 
}); 
  
it('menghabiskan stok sampai nol, tidak lebih (LP-4)', function () { 
    $kain = $this->penjaga->ambil([$this->kain->id])[$this->kain->id]; 
  
    $this->penjaga->kurangi($kain, 5); 
    expect($this->kain->refresh()->stok)->toBe(0); 
  
    expect(fn () => $this->penjaga->kurangi($kain, 1)) 
        ->toThrow(ProdukTidakDapatDibeli::class, 'Stok Kain Batik Cap tidak mencukupi.'); 
    expect($this->kain->refresh()->stok)->toBe(0); 
}); 
  
it('menolak pembelian melebihi stok dengan 409 dan tidak mengubah apa pun', function () { 
    $kain = $this->penjaga->ambil([$this->kain->id])[$this->kain->id]; 
  
    try { 
        $this->penjaga->kurangi($kain, 6); 
        $this->fail('Seharusnya ditolak.'); 
    } catch (ProdukTidakDapatDibeli $galat) { 
        expect($galat->kode)->toBe('LP-4') 
            ->and($galat->statusHttp)->toBe(409) 
            ->and($galat->rincian)->toMatchArray(['diminta' => 6, 'tersedia' => 5]); 
    } 
  
    expect($this->kain->refresh()->stok)->toBe(5); 
}); 
 
Terminal — Bagas 
./vendor/bin/pint 
./vendor/bin/pest 
git add app/Services/PenjagaStok.php app/Models/Produk.php tests/Feature/Katalog/PenjagaStokTest.php 
git commit -m "feat: penjaga stok sebagai satu-satunya pengubah stok (LP-4)" 
git push -u origin sesi-4/kontrak-stok-bagas 
gh pr create --base main --draft --web 

Hasil yang benar di cabang ini: 277 uji lulus, seluruhnya hijau, termasuk uji "menghabiskan stok sampai nol, tidak 
lebih". Ingat angka itu. Pada Langkah 9 kode yang sama menjual dua belas barang dari stok lima. 
PENTING — Hijau di Pest bukan bukti aman dari balapan 
Kedelapan uji pada berkas itu benar dan perlu. Tidak satu pun dapat merah karena ambil() belum mengunci, sebab 
Pest tidak pernah menjalankan dua pembeli bersamaan. Uji yang menjawab "apakah aturannya benar" dan uji yang 
menjawab "apakah aturannya bertahan saat dipanggil serentak" adalah dua alat yang berbeda.  
 
Langkah 5 — A4: Checkout, Pesanan, Gembok, dan Mesin Status (Rani) 
Cabang: sesi-4/kontrak-pesanan-rani. Kontrak terbesar pada jendela A: tiga tabel, penomor pesanan, dan dua 
kelas yang akan dipakai Dimas. 
5.1 Tiga tabel. 
database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php 
<?php 
  
use Illuminate\Database\Migrations\Migration; 
use Illuminate\Database\Schema\Blueprint; 
use Illuminate\Support\Facades\Schema; 
  
return new class extends Migration 
{ 
    /** 
     * LP-7: satu checkout melahirkan satu pesanan per toko dan dibayar dengan satu pembayaran. 
     * Baris checkout adalah induk keduanya, dan baris itulah yang dikunci setiap kali pesanan 
     * atau pembayarannya berubah. 
     */ 
    public function up(): void 
    { 
        Schema::create('checkout', function (Blueprint $tabel) { 
            $tabel->id(); 
            // Yang disebut klien dan gerbang pembayaran. Nomor urut internal tidak pernah keluar. 
            $tabel->char('kode', 26)->unique(); 
            $tabel->foreignId('pembeli_id')->constrained('pengguna')->restrictOnDelete(); 
            $tabel->unsignedBigInteger('total_rupiah'); 
            // Sesi yang membuat checkout ini. Sengaja bukan foreign key: baris sesi dipangkas 
            // setelah 30 hari, sedangkan jejak ini harus tinggal selama pesanannya ada. 
            $tabel->char('pengenal_sesi', 26); 
            $tabel->timestamps(); 
        }); 
  
        Schema::create('pesanan', function (Blueprint $tabel) { 
            $tabel->id(); 
            $tabel->foreignId('checkout_id')->constrained('checkout')->restrictOnDelete(); 
            // LP-9. Diisi sesaat setelah baris lahir, karena nomornya memuat id baris itu. 
            $tabel->string('nomor', 24)->nullable()->unique(); 
            $tabel->foreignId('pembeli_id')->constrained('pengguna')->restrictOnDelete(); 
            $tabel->foreignId('toko_id')->constrained('toko')->restrictOnDelete(); 
            $tabel->string('status', 20)->index(); 
            $tabel->unsignedBigInteger('subtotal_rupiah'); 
            $tabel->unsignedBigInteger('ongkir_rupiah'); 
            $tabel->unsignedBigInteger('total_rupiah'); 
            // Salinan alamat pada saat checkout, bukan penunjuk ke tabel alamat. 
            $tabel->json('alamat_kirim'); 
            $tabel->timestamp('dibayar_pada')->nullable(); 
            $tabel->timestamp('dibatalkan_pada')->nullable(); 
            $tabel->string('alasan_batal', 120)->nullable(); 
            $tabel->timestamps(); 
  
            $tabel->index(['pembeli_id', 'id']); 
        }); 
  
        Schema::create('butir_pesanan', function (Blueprint $tabel) { 
            $tabel->id(); 
            $tabel->foreignId('pesanan_id')->constrained('pesanan')->cascadeOnDelete(); 
            $tabel->foreignId('produk_id')->constrained('produk')->restrictOnDelete(); 
            // LP-6: nama dan harga dibekukan. Penjual boleh mengubah produknya besok; 

            // pesanan hari ini tetap mencatat apa yang dibeli dan berapa harganya. 
            $tabel->string('nama_produk', 120); 
            $tabel->unsignedBigInteger('harga_rupiah'); 
            $tabel->unsignedInteger('kuantitas'); 
            $tabel->unsignedBigInteger('subtotal_rupiah'); 
        }); 
    } 
  
    public function down(): void 
    { 
        Schema::dropIfExists('butir_pesanan'); 
        Schema::dropIfExists('pesanan'); 
        Schema::dropIfExists('checkout'); 
    } 
}; 
 
Kolom Mengapa begitu 
checkout.kode ULID, pengenal yang boleh dilihat klien. Nomor urut internal tidak pernah keluar dari server. 
checkout.pengenal_sesi 
Sesi yang membuat checkout ini. Bukan foreign key: baris sesi 
dipangkas setelah 30 hari, sedangkan jejak ini harus tinggal selama 
pesanannya ada. 
pesanan.nomor Boleh kosong selama sepersekian detik, karena nomornya memuat id baris yang baru diketahui setelah baris itu tersimpan 
pesanan.pembeli_id Sama dengan pembeli pada checkout induknya. Diulang di sini agar kueri "pesanan saya" tidak perlu bergabung ke tabel lain. 
pesanan.alamat_kirim Salinan Alamat::potret() sebagai JSON 
butir_pesanan.nama_produk, harga_rupiah Salinan dari produk pada saat checkout (LP-6) 
Seluruh foreign key ke pengguna, toko, produk restrictOnDelete: akun, toko, atau produk yang sudah memiliki pesanan tidak dapat dihapus 
 
5.2 Nomor pesanan. 
app/Domain/Pesanan/PenomorPesanan.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Domain\Pesanan; 
  
use DateTimeInterface; 
use DateTimeZone; 
  
/** 
 * LP-9: nomor pesanan berbentuk LPK-YYYYMMDD-NNNNNN dan unik seumur hidup sistem. 
 * 
 * Bagian angka adalah id baris pesanan. Yang membagikan angka unik kepada dua pembeli yang 
 * datang bersamaan adalah basis data, lewat AUTO_INCREMENT, bukan kode kita. Menghitung 
 * "nomor terakhir tambah satu" di PHP adalah cara tercepat menghasilkan dua pesanan kembar. 
 */ 
final class PenomorPesanan 
{ 
    public const POLA = '/\ALPK-\d{8}-\d{6,}\z/'; 
  
    private const ZONA_BISNIS = 'Asia/Jakarta'; 
  
    public static function untuk(int $idPesanan, DateTimeInterface $dibuatPada): string 
    { 
        // Waktu disimpan dalam UTC. Tanggal pada nomor mengikuti hari kerja Lapak, yaitu WIB. 
        $tanggal = \DateTimeImmutable::createFromInterface($dibuatPada) 
            ->setTimezone(new DateTimeZone(self::ZONA_BISNIS)) 
            ->format('Ymd'); 
  

        return sprintf('LPK-%s-%06d', $tanggal, $idPesanan); 
    } 
} 
 
LP-9 meminta nomor yang unik seumur hidup sistem. Cara yang pertama terpikir adalah mencari nomor terakhir 
hari ini lalu menambah satu. Itu baca, putuskan, tulis tanpa kunci, dan dua pembeli yang datang bersamaan akan 
menerima nomor yang sama. Lapak menyerahkan pembagian angka kepada basis data: AUTO_INCREMENT pada 
kolom id dirancang untuk membagikan angka berbeda kepada penulis serentak. Nomor pesanan hanya 
membungkus id itu dengan tanggal. 
Akibatnya enam angka di belakang tidak kembali ke 000001 setiap hari. LPK-20261027-000412 berarti pesanan ke-
412 sejak Lapak berdiri, dibuat pada 27 Oktober 2026 waktu Indonesia barat. 
tests/Unit/PenomorPesananTest.php 
<?php 
  
use App\Domain\Pesanan\PenomorPesanan; 
  
it('membentuk nomor dari tanggal dan id pesanan (LP-9)', function () { 
    $nomor = PenomorPesanan::untuk(123, new DateTimeImmutable('2026-10-27 03:15:00 UTC')); 
  
    expect($nomor)->toBe('LPK-20261027-000123') 
        ->and($nomor)->toMatch(PenomorPesanan::POLA); 
}); 
  
it('memakai tanggal WIB, bukan tanggal UTC', function () { 
    // 23.30 UTC tanggal 27 adalah 06.30 WIB tanggal 28. 
    expect(PenomorPesanan::untuk(7, new DateTimeImmutable('2026-10-27 23:30:00 UTC'))) 
        ->toBe('LPK-20261028-000007'); 
}); 
  
it('tidak memotong id yang melewati enam angka', function () { 
    expect(PenomorPesanan::untuk(1_234_567, new DateTimeImmutable('2026-10-27 03:00:00 UTC'))) 
        ->toBe('LPK-20261027-1234567') 
        ->toMatch(PenomorPesanan::POLA); 
}); 
 
5.3 Model. 
app/Models/Checkout.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Models; 
  
use Database\Factories\CheckoutFactory; 
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model; 
use Illuminate\Database\Eloquent\Relations\BelongsTo; 
use Illuminate\Database\Eloquent\Relations\HasMany; 
  
final class Checkout extends Model 
{ 
    /** @use HasFactory<CheckoutFactory> */ 
    use HasFactory; 
  
    protected $table = 'checkout'; 
  
    // Hanya dibuat LayananCheckout, tidak pernah dari masukan klien. 
    protected $fillable = []; 
  
    protected function casts(): array 
    { 
        return ['total_rupiah' => 'integer']; 
    } 
  
    /** @return BelongsTo<Pengguna, $this> */ 

    public function pembeli(): BelongsTo 
    { 
        return $this->belongsTo(Pengguna::class, 'pembeli_id'); 
    } 
  
    /** @return HasMany<Pesanan, $this> */ 
    public function pesanan(): HasMany 
    { 
        return $this->hasMany(Pesanan::class, 'checkout_id'); 
    } 
} 
 
app/Models/Pesanan.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Models; 
  
use App\Domain\Pesanan\PenomorPesanan; 
use App\Domain\Pesanan\StatusPesanan; 
use Database\Factories\PesananFactory; 
use Illuminate\Database\Eloquent\Builder; 
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model; 
use Illuminate\Database\Eloquent\Relations\BelongsTo; 
use Illuminate\Database\Eloquent\Relations\HasMany; 
  
final class Pesanan extends Model 
{ 
    /** @use HasFactory<PesananFactory> */ 
    use HasFactory; 
  
    protected $table = 'pesanan'; 
  
    // Status hanya berubah lewat MesinStatusPesanan. Tidak ada kolom yang diisi dari masukan klien. 
    protected $fillable = []; 
  
    protected function casts(): array 
    { 
        return [ 
            'status' => StatusPesanan::class, 
            'subtotal_rupiah' => 'integer', 
            'ongkir_rupiah' => 'integer', 
            'total_rupiah' => 'integer', 
            'alamat_kirim' => 'array', 
            'dibayar_pada' => 'datetime', 
            'dibatalkan_pada' => 'datetime', 
        ]; 
    } 
  
    protected static function booted(): void 
    { 
        // LP-9: nomor diturunkan dari id, maka baru dapat diisi setelah baris tersimpan. 
        self::created(function (Pesanan $pesanan): void { 
            $pesanan->forceFill([ 
                'nomor' => PenomorPesanan::untuk($pesanan->id, $pesanan->created_at), 
            ])->saveQuietly(); 
        }); 
    } 
  
    /** @return BelongsTo<Checkout, $this> */ 
    public function checkout(): BelongsTo 
    { 
        return $this->belongsTo(Checkout::class, 'checkout_id'); 
    } 
  
    /** @return BelongsTo<Toko, $this> */ 
    public function toko(): BelongsTo 
    { 
        return $this->belongsTo(Toko::class, 'toko_id'); 
    } 
  
    /** @return HasMany<ButirPesanan, $this> */ 
    public function butir(): HasMany 
    { 

        return $this->hasMany(ButirPesanan::class, 'pesanan_id'); 
    } 
  
    /** 
     * Pintu pembeli selalu berangkat dari scope ini. Nomor pesanan orang lain dijawab 404. 
     * 
     * @param  Builder<Pesanan>  $kueri 
     */ 
    public function scopeMilikPembeli(Builder $kueri, Pengguna $pembeli): void 
    { 
        $kueri->where('pesanan.pembeli_id', $pembeli->id); 
    } 
} 
 
Model ketiga, ButirPesanan, hanya memuat nama tabel, relasi ke pesanan, dan cast tiga kolom angkanya; 
berkasnya ada pada berkas pendamping. Ketiga model menulis protected $fillable = []. Tidak ada kolom 
pesanan yang boleh diisi dari masukan klien, sehingga Pesanan::create($request->all()) tidak dapat menulis 
apa pun. Service mengisinya dengan forceFill(), yang hanya dipanggil dari kode yang tahu persis apa yang 
ditulisnya. 
Tiga factory menyertai ketiga model dan tersedia pada berkas pendamping. Yang terpenting, PesananFactory: 
database/factories/PesananFactory.php — definisi dan state dibayar 
    public function definition(): array 
    { 
        return [ 
            'checkout_id' => Checkout::factory(), 
            // Pembeli sebuah pesanan selalu pembeli checkout-nya. 
            'pembeli_id' => fn (array $isi) => Checkout::query() 
                ->findOrFail($isi['checkout_id']) 
                ->pembeli_id, 
            'toko_id' => Toko::factory(), 
            'status' => StatusPesanan::MenungguPembayaran, 
            'subtotal_rupiah' => 185_000, 
            'ongkir_rupiah' => 12_000, 
            'total_rupiah' => 197_000, 
            'alamat_kirim' => [ 
                'nama_penerima' => 'Andi Nugroho', 'telepon' => '081200000008', 
                'jalan' => 'Jl. Slamet Riyadi No. 12', 'kota' => 'Surakarta', 'kode_pos' => '57111', 
            ], 
        ]; 
    } 
  
    public function dibayar(): static 
    { 
        return $this->state(['status' => StatusPesanan::Dibayar, 'dibayar_pada' => now()]); 
    } 
 
5.4 Gembok checkout. 
app/Services/GembokCheckout.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Models\Checkout; 
use Closure; 
use Illuminate\Support\Facades\DB; 
  
/** 
 * Satu gembok untuk satu transaksi belanja. 
 * 
 * Siapa pun yang mengubah pesanan atau pembayaran sebuah checkout menjalankan pekerjaannya di 
 * dalam dalam(). Baris checkout dikunci lebih dulu, sehingga konfirmasi pembayaran, pembatalan 
 * otomatis, dan kelak tindakan pembeli maupun penjual atas checkout yang sama ANTRE, tidak 
 * berjalan bersamaan. Karena semuanya mengambil gembok yang sama lebih dulu, tidak ada dua 
 * pekerjaan yang saling menunggu selamanya. 
 */ 

final class GembokCheckout 
{ 
    /** 
     * @template T 
     * 
     * @param  Closure(Checkout): T  $kerja 
     * @return T 
     */ 
    public function dalam(int $idCheckout, Closure $kerja): mixed 
    { 
        return DB::transaction(function () use ($idCheckout, $kerja) { 
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($idCheckout); 
  
            return $kerja($checkout); 
        }); 
    } 
} 
 
Kelas ini hanya memiliki satu metode pendek, dan seluruh keamanan pembayaran serta pembatalan bergantung 
padanya. Siapa pun yang hendak mengubah pesanan atau tagihan sebuah checkout menyerahkan pekerjaannya 
sebagai fungsi kepada dalam(). Kelas itu membuka transaksi, mengunci baris induk, lalu menjalankan fungsinya. 
Dimas memakainya pada Langkah 10 tanpa perlu tahu kolom apa yang dikunci. 
5.5 Mesin status. 
app/Exceptions/TransisiPesananTidakSah.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Exceptions; 
  
use App\Domain\Pesanan\StatusPesanan; 
  
final class TransisiPesananTidakSah extends GalatDomain 
{ 
    public function __construct(StatusPesanan $dari, StatusPesanan $ke) 
    { 
        parent::__construct( 
            'LP-10', 
            "Pesanan berstatus {$dari->value} tidak dapat dipindahkan ke {$ke->value}.", 
            409, 
        ); 
    } 
} 
 
app/Services/MesinStatusPesanan.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Domain\Pesanan\AturanTransisi; 
use App\Domain\Pesanan\PelakuPesanan; 
use App\Domain\Pesanan\StatusPesanan; 
use App\Exceptions\TransisiPesananTidakSah; 
use App\Models\Pesanan; 
use Illuminate\Support\Facades\DB; 
use LogicException; 
  
/** 
 * Satu-satunya pintu perubahan status pesanan (LP-10). 
 * Aturannya sendiri ada di AturanTransisi, yang ditulis dan diuji pada Sesi 3. 
 */ 
final class MesinStatusPesanan 
{ 
    /** 
     * Pemanggil wajib sudah berada di dalam GembokCheckout::dalam(). 
     * 
     * @throws TransisiPesananTidakSah 409 LP-10 bila perpindahan itu tidak ada jalurnya 

     */ 
    public function pindahkan( 
        Pesanan $pesanan, 
        StatusPesanan $ke, 
        PelakuPesanan $oleh, 
        ?string $alasanBatal = null, 
    ): Pesanan { 
        if (DB::transactionLevel() === 0) { 
            throw new LogicException('Status pesanan hanya boleh diubah di dalam GembokCheckout.'); 
        } 
  
        // Status dibaca ULANG dengan kunci. Salinan di tangan pemanggil bisa sudah basi, 
        // dan pembacaan biasa di dalam transaksi MySQL melihat potret lama, bukan keadaan terkini. 
        $terkini = Pesanan::query()->lockForUpdate()->findOrFail($pesanan->id); 
  
        if (! AturanTransisi::boleh($terkini->status, $ke, $oleh)) { 
            throw new TransisiPesananTidakSah($terkini->status, $ke); 
        } 
  
        $cap = match ($ke) { 
            StatusPesanan::Dibayar => ['dibayar_pada' => now()], 
            StatusPesanan::Dibatalkan => [ 
                'dibatalkan_pada' => now(), 
                'alasan_batal' => $alasanBatal, 
            ], 
            default => [], 
        }; 
  
        $terkini->forceFill(['status' => $ke, ...$cap])->save(); 
  
        return $terkini; 
    } 
} 
 
Titipan pertama Sesi 3 lunas di sini: AturanTransisi yang ditulis dan diuji tanpa tabel kini memutuskan 
perpindahan status yang sebenarnya. Mesin status menambahkan dua hal yang tidak dapat dilakukan kelas murni 
itu. Ia menolak bekerja di luar transaksi, dan ia membaca ulang status dengan kunci sebelum memutuskan. Salinan 
pesanan di tangan pemanggil bisa sudah basi, sedangkan AturanTransisi::boleh() harus ditanya tentang status 
terkini. 
tests/Feature/Belanja/PesananTest.php — gembok dan mesin status 
it('membatalkan seluruh pekerjaan di dalam gembok bila salah satunya gagal', function () { 
    $checkout = Checkout::factory()->create(); 
    [$a, $b] = Pesanan::factory()->count(2)->for($checkout)->create(); 
  
    try { 
        $this->gembok->dalam($checkout->id, function () use ($a, $b) { 
            $this->mesin->pindahkan($a, StatusPesanan::Dibayar, PelakuPesanan::Sistem); 
            // Pembeli tidak pernah boleh menyatakan pesanannya dibayar. 
            $this->mesin->pindahkan($b, StatusPesanan::Dibayar, PelakuPesanan::Pembeli); 
        }); 
    } catch (TransisiPesananTidakSah) { 
    } 
  
    expect($a->refresh()->status)->toBe(StatusPesanan::MenungguPembayaran) 
        ->and($b->refresh()->status)->toBe(StatusPesanan::MenungguPembayaran); 
}); 
  
it('menandai pesanan dibayar atas nama sistem dan mencatat waktunya', function () { 
    $pesanan = Pesanan::factory()->create(); 
  
    $hasil = $this->gembok->dalam($pesanan->checkout_id, fn () => $this->mesin->pindahkan( 
        $pesanan, StatusPesanan::Dibayar, PelakuPesanan::Sistem, 
    )); 
  
    expect($hasil->status)->toBe(StatusPesanan::Dibayar) 
        ->and($pesanan->refresh()->dibayar_pada)->not->toBeNull(); 
}); 
  
it('membatalkan pesanan beserta alasan dan waktunya', function () { 
    $pesanan = Pesanan::factory()->create(); 
  
    $this->gembok->dalam($pesanan->checkout_id, fn () => $this->mesin->pindahkan( 

        $pesanan, StatusPesanan::Dibatalkan, PelakuPesanan::Sistem, 'Tidak dibayar dalam 24 jam', 
    )); 
  
    expect($pesanan->refresh()) 
        ->status->toBe(StatusPesanan::Dibatalkan) 
        ->alasan_batal->toBe('Tidak dibayar dalam 24 jam') 
        ->dibatalkan_pada->not->toBeNull(); 
}); 
  
it('menolak perpindahan yang tidak ada jalurnya dengan 409 (LP-10)', function ( 
    StatusPesanan $dari, 
    StatusPesanan $ke, 
    PelakuPesanan $oleh, 
) { 
    $pesanan = Pesanan::factory()->create(['status' => $dari]); 
  
    try { 
        $this->gembok->dalam( 
            $pesanan->checkout_id, 
            fn () => $this->mesin->pindahkan($pesanan, $ke, $oleh), 
        ); 
        $this->fail('Seharusnya ditolak.'); 
    } catch (TransisiPesananTidakSah $galat) { 
        expect($galat->kode)->toBe('LP-10')->and($galat->statusHttp)->toBe(409); 
    } 
  
    expect($pesanan->refresh()->status)->toBe($dari); 
})->with([ 
    'pembeli mengaku sudah membayar' => [ 
        StatusPesanan::MenungguPembayaran, StatusPesanan::Dibayar, PelakuPesanan::Pembeli, 
    ], 
    'dibayar dua kali' => [StatusPesanan::Dibayar, StatusPesanan::Dibayar, PelakuPesanan::Sistem], 
    'hidup lagi setelah batal' => [ 
        StatusPesanan::Dibatalkan, StatusPesanan::Dibayar, PelakuPesanan::Sistem, 
    ], 
    'batal setelah dikirim' => [ 
        StatusPesanan::Dikirim, StatusPesanan::Dibatalkan, PelakuPesanan::Pembeli, 
    ], 
]); 
  
it('memutuskan dari status terkini di basis data, bukan dari salinan pemanggil', function () { 
    $pesanan = Pesanan::factory()->create(); 
    $salinanBasi = Pesanan::query()->findOrFail($pesanan->id); 
  
    $this->gembok->dalam($pesanan->checkout_id, fn () => $this->mesin->pindahkan( 
        $pesanan, StatusPesanan::Dibatalkan, PelakuPesanan::Sistem, 'Kedaluwarsa', 
    )); 
  
    // Salinan basi masih berkata "menunggu", tetapi pembayaran yang datang terlambat tetap ditolak. 
    expect($salinanBasi->status)->toBe(StatusPesanan::MenungguPembayaran) 
        ->and(fn () => $this->gembok->dalam($pesanan->checkout_id, fn () => $this->mesin->pindahkan( 
            $salinanBasi, StatusPesanan::Dibayar, PelakuPesanan::Sistem, 
        )))->toThrow(TransisiPesananTidakSah::class); 
}); 
 
Uji terakhir pada cuplikan itu menirukan pemanggil yang memegang salinan basi: pesanan dimuat ketika masih 
menunggu pembayaran, statusnya diubah di basis data, lalu mesin diminta memindahkan salinan lama. Mesin 
memutuskan dari status terkini dan menolak. 
Terminal — Rani 
php artisan migrate 
./vendor/bin/pint 
./vendor/bin/pest 
git add database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php \ 
        app/Models/Checkout.php app/Models/Pesanan.php app/Models/ButirPesanan.php \ 
        database/factories app/Domain/Pesanan/PenomorPesanan.php tests/Unit/PenomorPesananTest.php 
git commit -m "feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9)" 
git add app/Exceptions/TransisiPesananTidakSah.php app/Services/GembokCheckout.php \ 
        app/Services/MesinStatusPesanan.php tests/Feature/Belanja/PesananTest.php 
git commit -m "feat: gembok checkout dan mesin status pesanan (LP-10)" 
git push -u origin sesi-4/kontrak-pesanan-rani 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 284 uji lulus. 

CATATAN — Versi pertama Rani memuat satu kolom yang salah nama 
Pada unggahan pertama, kolom total pada tabel checkout bernama total, bukan total_rupiah. Kesalahan kecil itu 
sengaja dipertahankan pada alur ini karena akibatnya adalah pokok Langkah 6. Berkas versi pertama tersedia pada 
berkas pendamping, folder varian/A4-sebelum-tinjauan/. 
 
Langkah 6 — A5 di Atas Cabang Rekan, Tinjauan, dan Merge Jendela A (Dimas, lalu Semua) 
6.1 Dimas menumpuk cabangnya. Menit ke-70. Draf Rani sudah di remote tetapi belum ditinjau. Dimas tidak 
menunggu: ia membuat cabangnya dari cabang Rani, bukan dari main. 
Terminal — Dimas 
$ git fetch origin 
$ git switch -c sesi-4/kontrak-pembayaran-dimas origin/sesi-4/kontrak-pesanan-rani 
branch 'sesi-4/kontrak-pembayaran-dimas' set up to track 'origin/sesi-4/kontrak-pesanan-rani'. 
Switched to a new branch 'sesi-4/kontrak-pembayaran-dimas' 
 
Tabel pembayaran kini dapat menunjuk ke tabel checkout, karena migration Rani ada di cabang ini. 
database/migrations/2026_10_04_400001_buat_tabel_pembayaran.php 
<?php 
  
use Illuminate\Database\Migrations\Migration; 
use Illuminate\Database\Schema\Blueprint; 
use Illuminate\Support\Facades\Schema; 
  
return new class extends Migration 
{ 
    public function up(): void 
    { 
        Schema::create('pembayaran', function (Blueprint $tabel) { 
            $tabel->id(); 
            // LP-7: unique() menjamin satu checkout dibayar dengan tepat satu pembayaran. 
            $tabel->foreignId('checkout_id')->unique()->constrained('checkout')->restrictOnDelete(); 
            // Yang disebut pembeli dan gerbang pembayaran, misalnya BYR-01K7... 
            $tabel->string('kode', 30)->unique(); 
            $tabel->unsignedBigInteger('jumlah_rupiah'); 
            $tabel->string('status', 20)->index(); 
            $tabel->timestamp('batas_bayar_pada'); 
            $tabel->timestamp('lunas_pada')->nullable(); 
            // Pengenal peristiwa dari gerbang yang melunasi tagihan ini. Unik: satu peristiwa 
            // tidak dapat melunasi dua tagihan. 
            $tabel->string('id_peristiwa', 64)->nullable()->unique(); 
            $tabel->timestamps(); 
        }); 
    } 
  
    public function down(): void 
    { 
        Schema::dropIfExists('pembayaran'); 
    } 
}; 
 
unique() pada checkout_id menegakkan separuh kedua LP-7 di tingkat basis data: satu checkout, tepat satu 
pembayaran. Tagihan kedua untuk checkout yang sama ditolak MySQL sendiri, apa pun yang terjadi di kode aplikasi. 
app/Enums/StatusPembayaran.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Enums; 
  
enum StatusPembayaran: string 
{ 
    case Menunggu = 'menunggu'; 

    case Lunas = 'lunas'; 
  
    /** Batas bayar terlewat. Pesanannya dibatalkan dan stoknya dikembalikan (LP-10). */ 
    case Kedaluwarsa = 'kedaluwarsa'; 
} 
 
app/Models/Pembayaran.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Models; 
  
use App\Enums\StatusPembayaran; 
use Database\Factories\PembayaranFactory; 
use Illuminate\Database\Eloquent\Builder; 
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model; 
use Illuminate\Database\Eloquent\Relations\BelongsTo; 
  
final class Pembayaran extends Model 
{ 
    /** @use HasFactory<PembayaranFactory> */ 
    use HasFactory; 
  
    protected $table = 'pembayaran'; 
  
    // Hanya dibuat dan diubah LayananPembayaran. 
    protected $fillable = []; 
  
    protected function casts(): array 
    { 
        return [ 
            'status' => StatusPembayaran::class, 
            'jumlah_rupiah' => 'integer', 
            'batas_bayar_pada' => 'datetime', 
            'lunas_pada' => 'datetime', 
        ]; 
    } 
  
    /** @return BelongsTo<Checkout, $this> */ 
    public function checkout(): BelongsTo 
    { 
        return $this->belongsTo(Checkout::class, 'checkout_id'); 
    } 
  
    /** 
     * Tagihan yang masih menunggu padahal batas bayarnya sudah lewat (LP-10). 
     * 
     * @param  Builder<Pembayaran>  $kueri 
     */ 
    public function scopeLewatBatas(Builder $kueri): void 
    { 
        $kueri->where('status', StatusPembayaran::Menunggu)->where('batas_bayar_pada', '<=', now()); 
    } 
} 
 
app/Http/Resources/PembayaranResource.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Resources; 
  
use App\Domain\Uang; 
use App\Models\Pembayaran; 
use Illuminate\Http\Request; 
use Illuminate\Http\Resources\Json\JsonResource; 
  
/** 
 * @mixin Pembayaran 
 */ 

final class PembayaranResource extends JsonResource 
{ 
    /** id_peristiwa dan nomor urut internal tidak dikeluarkan. */ 
    public function toArray(Request $request): array 
    { 
        $jumlah = Uang::dari($this->jumlah_rupiah); 
  
        return [ 
            'kode' => $this->kode, 
            'status' => $this->status->value, 
            'jumlah' => ['rupiah' => $jumlah, 'tampilan' => $jumlah->format()], 
            'batas_bayar_pada' => $this->batas_bayar_pada->toIso8601String(), 
            'lunas_pada' => $this->lunas_pada?->toIso8601String(), 
        ]; 
    } 
} 
 
app/Services/LayananPembayaran.php — versi kontrak (setelah perbaikan 6.5), isi kelas 
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
 
Kontrak Dimas kepada Rani adalah tiga metode: buat() dipanggil checkout, untuk() dipakai saat menampilkan, 
dan tandaiKedaluwarsa() dipanggil pembatalan otomatis. Metode ketiga membaca ulang tagihan dengan kunci, 
karena di antara penjadwal memilih tagihan itu dan gembok didapat, pembayarannya bisa saja sudah masuk. 
tests/Feature/Dompet/PembayaranTest.php — setelah perbaikan 6.5, tiga uji pertama 
<?php 
  
use App\Enums\StatusPembayaran; 
use App\Models\Checkout; 

use App\Models\Pembayaran; 
use App\Services\GembokCheckout; 
use App\Services\LayananPembayaran; 
use Illuminate\Database\UniqueConstraintViolationException; 
  
beforeEach(function () { 
    $this->layanan = app(LayananPembayaran::class); 
    $this->checkout = Checkout::factory()->create(['total_rupiah' => 567_500]); 
}); 
  
it('membuat tagihan sebesar total checkout dengan batas bayar 24 jam (LP-7, LP-10)', function () { 
    $this->freezeTime(); 
  
    $pembayaran = $this->layanan->buat($this->checkout); 
  
    expect($pembayaran->jumlah_rupiah)->toBe(567_500) 
        ->and($pembayaran->status)->toBe(StatusPembayaran::Menunggu) 
        ->and($pembayaran->kode)->toMatch('/\ABYR-[0-9A-Z]{26}\z/') 
        ->and($pembayaran->batas_bayar_pada->toDateTimeString()) 
        ->toBe(now()->addHours(24)->toDateTimeString()) 
        ->and($this->layanan->untuk($this->checkout)->is($pembayaran))->toBeTrue(); 
}); 
  
it('menolak tagihan kedua untuk checkout yang sama (LP-7)', function () { 
    $this->layanan->buat($this->checkout); 
    $this->layanan->buat($this->checkout); 
})->throws(UniqueConstraintViolationException::class); 
  
it('hanya menandai kedaluwarsa tagihan yang menunggu dan sudah lewat batas', function () { 
    $pembayaran = $this->layanan->buat($this->checkout); 
    $tandai = fn () => app(GembokCheckout::class)->dalam( 
        $this->checkout->id, 
        fn (Checkout $terkunci) => $this->layanan->tandaiKedaluwarsa($terkunci), 
    ); 
  
    expect($tandai())->toBeFalse() 
        ->and($pembayaran->refresh()->status)->toBe(StatusPembayaran::Menunggu); 
  
    $this->travel(24)->hours(); 
    $this->travel(1)->seconds(); 
  
    expect($tandai())->toBeTrue() 
        ->and($pembayaran->refresh()->status)->toBe(StatusPembayaran::Kedaluwarsa) 
        ->and($tandai())->toBeFalse();   // sudah kedaluwarsa: tidak ada yang berubah lagi 
}); 
 
Pada unggahan pertamanya, Dimas menulis $checkout->total di layanan dan ['total' => 567_500] di uji, 
mengikuti nama kolom yang ia lihat di cabang Rani. Ujinya hijau: 289 lulus. 
Terminal — Dimas 
git add database/migrations/2026_10_04_400001_buat_tabel_pembayaran.php \ 
        app/Enums/StatusPembayaran.php app/Models/Pembayaran.php \ 
        database/factories/PembayaranFactory.php app/Http/Resources/PembayaranResource.php 
git commit -m "feat: tabel pembayaran, satu untuk setiap checkout (LP-7)" 
git add app/Services/LayananPembayaran.php tests/Feature/Dompet/PembayaranTest.php 
git commit -m "feat: layanan pembayaran membuat tagihan dan menandai kedaluwarsa" 
git push -u origin sesi-4/kontrak-pembayaran-dimas 
gh pr create --base sesi-4/kontrak-pesanan-rani --draft --web 
 
Keluaran — cabang Dimas memuat empat commit, dua di antaranya milik Rani 
$ git log --oneline --format="%h %an: %s" origin/main..HEAD 
d506447 Dimas: feat: layanan pembayaran membuat tagihan dan menandai kedaluwarsa 
2e1edc9 Dimas: feat: tabel pembayaran, satu untuk setiap checkout (LP-7) 
852b725 Rani: feat: gembok checkout dan mesin status pesanan (LP-10) 
c86d637 Rani: feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
 
TIPS — PR bertumpuk: arahkan ke cabang yang ditumpangi 

Perhatikan --base sesi-4/kontrak-pesanan-rani. Dengan dasar itu, halaman PR Dimas hanya menampilkan dua 
commit miliknya, sehingga peninjau tidak membaca ulang pekerjaan Rani. Menurut dokumentasi GitHub, ketika 
cabang dasar sebuah PR dihapus setelah di-merge, PR yang menumpang padanya otomatis diarahkan ulang ke cabang 
tujuan merge itu, di sini main. Periksa kolom dasar pada halaman PR setelah A4 masuk; bila belum berubah, ubah 
sendiri lewat tombol Edit di samping judul PR. 
 
6.2 Tinjauan A4: nama kolom. Bagas meninjau A4 dengan menjalankannya, lalu membaca migration-nya terhadap 
docs/PETA-SKEMA.md. Komentarnya satu baris: aturan kolom tim sejak Sesi 1 mewajibkan kolom uang berakhiran 
_rupiah (LP-1), dan checkout.total melanggarnya. 
Rani memperbaiki tiga berkas. Ia tidak menambahkan commit keempat berjudul "perbaiki nama kolom". Ia melebur 
perbaikannya ke commit yang diperbaiki. 
Terminal — Rani 
$ git log --oneline main..HEAD 
852b725 feat: gembok checkout dan mesin status pesanan (LP-10) 
c86d637 feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
  
$ git status -s 
 M app/Models/Checkout.php 
 M database/factories/CheckoutFactory.php 
 M database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php 
  
$ git add -A 
$ git commit --fixup=c86d637 
[sesi-4/kontrak-pesanan-rani cbfc885] fixup! feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
 3 files changed, 3 insertions(+), 3 deletions(-) 
  
$ git log --oneline main..HEAD 
cbfc885 fixup! feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
852b725 feat: gembok checkout dan mesin status pesanan (LP-10) 
c86d637 feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
  
$ git rebase -i --autosquash main 
Rebasing (2/3) 
Rebasing (3/3) 
  
Successfully rebased and updated refs/heads/sesi-4/kontrak-pesanan-rani. 
  
$ git log --oneline main..HEAD 
ff3d985 feat: gembok checkout dan mesin status pesanan (LP-10) 
bfc3b96 feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
  
$ git push --force-with-lease origin sesi-4/kontrak-pesanan-rani 
To /home/claude/mp/sim4/origin.git 
 + 852b725...ff3d985 sesi-4/kontrak-pesanan-rani -> sesi-4/kontrak-pesanan-rani (forced update) 
 
Perintah Yang dilakukannya 
git commit --fixup=<hash> Membuat commit berjudul fixup! <judul commit sasaran>. Isinya perbaikan saja. 
git rebase -i --autosquash main 
Membuka daftar commit dengan commit fixup! sudah dipindahkan ke bawah 
sasarannya dan ditandai untuk dilebur. Simpan dan tutup penyuntingnya tanpa 
mengubah apa pun. 
git push --force-with-lease Mengganti cabang di remote. Wajib, karena kedua commit lama diganti dua commit baru. 
 
Lihat hash pada keluaran terakhir. Kedua commit Rani berganti hash, termasuk commit kedua yang isinya tidak 
berubah sama sekali, karena induknya berubah. Cabang Dimas masih berdiri di atas kedua commit lama. Tidak ada 
yang memberi tahu Dimas, dan Git pun belum mengeluh. 

Migration checkout — yang diubah Rani setelah tinjauan 
             $tabel->foreignId('pembeli_id')->constrained('pengguna')->restrictOnDelete(); 
-            $tabel->unsignedBigInteger('total'); 
+            $tabel->unsignedBigInteger('total_rupiah'); 
             // Sesi yang membuat checkout ini. Sengaja bukan foreign key: baris sesi dipangkas 
 
6.3 Merge A1 sampai A4. Setelah keempatnya disetujui, Dimas me-merge berurutan. Setiap penulis menyegarkan 
cabangnya lebih dulu, seperti biasa: 
Terminal — penulis PR, sesaat sebelum gilirannya 
git fetch origin 
git rebase origin/main 
./vendor/bin/pest 
git push --force-with-lease 
 
Tidak ada konflik. A1 dan A2 sama-sama menyunting docs/KONTRAK-API.md, tetapi pada bagian yang berbeda, dan 
Git menggabungkannya sendiri. Jumlah uji pada main setelah setiap merge: 281, 293, 301, lalu 316. 
6.4 Giliran A5: rebase yang berkonflik pada berkas orang lain. Dimas menyegarkan cabangnya dengan perintah 
yang sama. 
Terminal — Dimas 
$ git rebase origin/main 
Auto-merging app/Models/Checkout.php 
CONFLICT (add/add): Merge conflict in app/Models/Checkout.php 
Auto-merging database/factories/CheckoutFactory.php 
CONFLICT (add/add): Merge conflict in database/factories/CheckoutFactory.php 
Auto-merging database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php 
CONFLICT (add/add): Merge conflict in 
database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php 
warning: skipped previously applied commit 852b725 
hint: use --reapply-cherry-picks to include skipped commits 
hint: Disable this message with "git config advice.skippedCherryPicks false" 
Rebasing (1/3) 
error: could not apply c86d637... feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
hint: Resolve all conflicts manually, mark them as resolved with 
hint: "git add/rm <conflicted_files>", then run "git rebase --continue". 
hint: You can instead skip this commit: run "git rebase --skip". 
hint: To abort and get back to the state before "git rebase", run "git rebase --abort". 
Could not apply c86d637... feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
 
Tiga berkas berkonflik, dan ketiganya milik Rani. Dimas tidak pernah menyentuh satu pun. Keluaran git status 
menjelaskan apa yang sedang dicoba Git: 
Keluaran — git status di tengah rebase 
$ git status 
interactive rebase in progress; onto 1987884 
Last command done (1 command done): 
   pick c86d637 feat: tabel checkout, pesanan, dan butir pesanan (LP-7, LP-9) 
Next commands to do (2 remaining commands): 
   pick 2e1edc9 feat: tabel pembayaran, satu untuk setiap checkout (LP-7) 
   pick d506447 feat: layanan pembayaran membuat tagihan dan menandai kedaluwarsa 
  (use "git rebase --edit-todo" to view and edit) 
You are currently rebasing branch 'sesi-4/kontrak-pembayaran-dimas' on '1987884'. 
  (fix conflicts and then run "git rebase --continue") 
  (use "git rebase --skip" to skip this patch) 
  (use "git rebase --abort" to check out the original branch) 
  
Unmerged paths: 
  (use "git restore --staged <file>..." to unstage) 
  (use "git add <file>..." to mark resolution) 
    both added:      app/Models/Checkout.php 
    both added:      database/factories/CheckoutFactory.php 
    both added:      database/migrations/2026_10_04_300001_buat_tabel_checkout_dan_pesanan.php 
  
no changes added to commit (use "git add" and/or "git commit -a") 
 

Git sedang memasang ulang commit feat: tabel checkout, pesanan, dan butir pesanan di atas main. Itu 
commit Rani versi lama. main sudah memuat versi barunya, dengan isi yang berbeda pada tiga berkas itu. Dari 
sudut pandang Git, dua commit berbeda sama-sama menambahkan berkas yang sama dengan isi berlainan. 
Baris warning: skipped previously applied commit layak dibaca dua kali. Commit kedua Rani, yang isinya 
tidak berubah, dikenali Git sebagai sudah ada di main walau hash-nya berbeda, lalu dilewati. Git membandingkan isi 
perubahannya, bukan hash-nya. Seandainya Rani hanya me-rebase tanpa mengubah isi, kedua commit lamanya 
akan dilewati dengan cara itu dan Dimas tidak akan melihat konflik apa pun. Yang membuat rebase biasa gagal 
adalah isi yang berubah lewat fixup. 
Menyelesaikan konflik itu dengan tangan adalah jalan yang salah: Dimas akan memutuskan isi berkas Rani, di 
cabangnya sendiri. Ia membatalkan rebase. 
Terminal — Dimas 
$ git rebase --abort 
$ git status -sb 
## sesi-4/kontrak-pembayaran-dimas...origin/sesi-4/kontrak-pembayaran-dimas 
 
6.5 git rebase --onto. Yang Dimas inginkan sebenarnya sederhana: ambil dua commit miliknya saja, lalu pasang di 
atas main. Commit Rani yang lama ditinggalkan, karena penggantinya sudah ada di main. 
Terminal — Dimas 
$ git rebase --onto origin/main HEAD~2 
Rebasing (1/2) 
Rebasing (2/2) 
  
Successfully rebased and updated refs/heads/sesi-4/kontrak-pembayaran-dimas. 
  
$ git log --oneline --format="%h %an: %s" origin/main..HEAD 
ff96118 Dimas: feat: layanan pembayaran membuat tagihan dan menandai kedaluwarsa 
a774326 Dimas: feat: tabel pembayaran, satu untuk setiap checkout (LP-7) 
 
Membaca git rebase --onto origin/main HEAD~2 
git rebase --onto  <dasar baru>  <dasar lama> 
                   origin/main   HEAD~2 
  
"Ambil semua commit SETELAH <dasar lama> sampai ujung cabang ini, 
 lalu pasang di atas <dasar baru>." 
  
sebelum    main ---------------------------- M (memuat R1', R2') 
              \ 
               R1 --- R2 --- D1 --- D2        <- cabang Dimas; HEAD~2 adalah R2 
                      ^dasar lama 
  
sesudah    main ---------------------------- M --- D1' --- D2' 
 
HEAD~2 berarti dua commit di bawah ujung cabang, yaitu commit terakhir milik Rani. Dimas mengetahui angkanya 
dari keluaran git log pada 6.1: dua commit teratas miliknya. Hash commit itu dapat dipakai sebagai gantinya dan 
lebih aman bila jumlah commit Anda banyak. 
Rebase berhasil tanpa konflik. Pekerjaan belum selesai: 
Keluaran — uji pembayaran setelah rebase --onto (diringkas) 
$ ./vendor/bin/pest tests/Feature/Dompet/PembayaranTest.php 
  
   FAIL  Tests\Feature\Dompet\PembayaranTest 
  ⨯ it membuat tagihan sebesar total checkout dengan batas bayar 24 jam (LP-7, LP-10) 
  ⨯ it menolak tagihan kedua untuk checkout yang sama (LP-7) 
  ⨯ it hanya menandai kedaluwarsa tagihan yang menunggu dan sudah lewat batas 
  ⨯ it tidak menandai kedaluwarsa tagihan yang sudah lunas walau batasnya lewat 
  ⨯ it menemukan tagihan yang lewat batas lewat scope 
  ──────────────────────────────────────────────────────────────────────────── 

   FAILED  Tests\Feature\Dompet\PembayaranTest > it membuat…   QueryException 
  SQLSTATE[HY000]: General error: 1 table checkout has no column named total 
  (Connection: sqlite, SQL: insert into "checkout" ("kode", "pembeli_id", "total_rupiah", "pe ... 
  
  (empat galat berikutnya sama: kolom "total" tidak ada) 
  
  Tests:    5 failed (0 assertions) 
 
Kelima uji pembayaran merah. Kode Dimas masih menulis ke kolom total, yang sudah tidak ada. Ini konflik makna 
seperti pada Sesi 3 Langkah 10: Git selesai tanpa keluhan, dan yang menangkapnya adalah uji. 
Perbaikan Dimas 
baff088 fix: ikuti nama kolom checkout.total_rupiah 
  
 app/Services/LayananPembayaran.php      | 2 +- 
 tests/Feature/Dompet/PembayaranTest.php | 2 +- 
 2 files changed, 2 insertions(+), 2 deletions(-) 
diff --git a/app/Services/LayananPembayaran.php b/app/Services/LayananPembayaran.php 
index a17f1f0..a08747e 100644 
--- a/app/Services/LayananPembayaran.php 
+++ b/app/Services/LayananPembayaran.php 
@@ -25,5 +25,5 @@ public function buat(Checkout $checkout): Pembayaran 
             'kode' => 'BYR-'.Str::ulid(), 
             // Jumlahnya disalin dari checkout, tidak pernah dari klien (LP-6). 
-            'jumlah_rupiah' => $checkout->total, 
+            'jumlah_rupiah' => $checkout->total_rupiah, 
             'status' => StatusPembayaran::Menunggu, 
             'batas_bayar_pada' => now()->addHours(config('lapak.batas_bayar_jam')), 
diff --git a/tests/Feature/Dompet/PembayaranTest.php b/tests/Feature/Dompet/PembayaranTest.php 
index f55c0dc..dd9b04f 100644 
--- a/tests/Feature/Dompet/PembayaranTest.php 
+++ b/tests/Feature/Dompet/PembayaranTest.php 
@@ -10,5 +10,5 @@ 
 beforeEach(function () { 
     $this->layanan = app(LayananPembayaran::class); 
-    $this->checkout = Checkout::factory()->create(['total' => 567_500]); 
+    $this->checkout = Checkout::factory()->create(['total_rupiah' => 567_500]); 
 }); 
 
Terminal — Dimas 
git add app/Services/LayananPembayaran.php tests/Feature/Dompet/PembayaranTest.php 
git commit -m "fix: ikuti nama kolom checkout.total_rupiah" 
./vendor/bin/pest 
git push --force-with-lease origin sesi-4/kontrak-pembayaran-dimas 
 
Hasil yang benar: 321 uji lulus. Rani meninjau dan Dimas me-merge PR #39. 
PENTING — Tiga kebiasaan saat menumpang cabang rekan 
Pertama, beri tahu pemilik cabang bahwa Anda menumpang, supaya ia mengabari Anda sebelum menulis ulang 
riwayatnya. Kedua, catat commit terakhir milik rekan saat Anda mulai; itulah <dasar lama> Anda kelak. Ketiga, 
jangan pernah menyelesaikan konflik pada berkas yang bukan milik Anda saat menyegarkan cabang: itu tanda Anda 
sedang memasang ulang commit orang lain, dan obatnya --onto, bukan penyunting konflik. 
 
6.6 Membuka lima cabang implementasi. Sebelum pertemuan berakhir: 
Terminal — Dimas, setelah kelima kontrak masuk 
git switch main 
git pull origin main 
php artisan migrate:fresh --seed 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
php artisan route:list --path=api/v1 
 

Hasil yang benar: 321 uji lulus pada kedua basis data dan 34 rute. Jendela A hanya menambah satu rute, GET 
/alamat. 
Anggota Cabang 
Bambang sesi-4/alamat-bambang 
Rani sesi-4/checkout-rani 
Bagas sesi-4/kunci-stok-bagas 
Dimas sesi-4/webhook-dimas 
Sekar sesi-4/balapan-ci-sekar 
 
 
 

Bagian 3 — Jendela B: Implementasi 
 
Kelima cabang berangkat dari main yang memuat lima kontrak. Setiap PR dibuka sebagai draf paling lambat H+2 
dan di-merge berurutan pada H+5. Tiga kebiasaan baru berlaku untuk seluruh jendela ini. 
1. Uji Pest dijalankan pada SQLite selama bekerja dan pada MySQL sebelum meminta tinjauan. 
2. Setiap PR membawa skenario balapan untuk aturannya sendiri, dan keluarannya dari MySQL ditempel pada 
deskripsi PR. 
3. Sebelum memasang kunci, jalankan skenario itu terhadap kode yang belum dikunci dan simpan keluaran 
pelanggarannya. Skenario yang tidak pernah Anda lihat merah belum terbukti dapat merah. 
Langkah 7 — B1: Buku Alamat dengan Kunci Baris Induk (Bambang) 
Cabang: sesi-4/alamat-bambang. Menyimpan alamat tampak tidak berbahaya. Di dalamnya ada dua aturan yang 
sama-sama berbentuk keputusan atas sekumpulan baris: alamat pertama menjadi alamat utama, dan seorang 
pengguna memiliki paling banyak lima alamat. 
7.1 Layanan. 
app/Services/BukuAlamat.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Exceptions\GalatDomain; 
use App\Models\Alamat; 
use App\Models\Pengguna; 
use Closure; 
use Illuminate\Support\Collection; 
use Illuminate\Support\Facades\DB; 
  
/** 
 * Satu-satunya pintu perubahan alamat. Menjaga dua aturan yang tidak dapat dijaga per baris: 
 *   - pengguna yang memiliki alamat selalu memiliki TEPAT SATU alamat utama, dan 
 *   - jumlah alamat tidak melebihi batas. 
 * 
 * Keduanya adalah keputusan atas SEKUMPULAN baris. Dua permintaan yang datang bersamaan dapat 
 * sama-sama melihat "belum ada alamat" lalu sama-sama menjadikan alamatnya utama. Maka setiap 
 * perubahan mengunci baris PENGGUNA lebih dulu: gembok induk yang selalu ada, bahkan ketika 
 * pengguna itu belum memiliki alamat satu pun untuk dikunci. 
 */ 
final class BukuAlamat 
{ 
    /** 
     * @param  array<string, string>  $data  hasil validasi AlamatRequest 
     */ 
    public function tambah(Pengguna $pemilik, array $data): Alamat 
    { 
        return $this->terkunci($pemilik, function (Collection $punya) use ($pemilik, $data) { 
            if ($punya->count() >= config('lapak.transaksi.alamat_maksimum')) { 
                throw new GalatDomain('ALAMAT_PENUH', 'Jumlah alamat sudah mencapai batas.', 422); 
            } 
  
            $alamat = new Alamat($data); 
            $alamat->pengguna_id = $pemilik->id; 
            // Alamat pertama otomatis menjadi alamat utama. 
            $alamat->utama = $punya->isEmpty(); 
            $alamat->save(); 
  
            return $alamat; 
        }); 
    } 
  
    public function jadikanUtama(Pengguna $pemilik, int $idAlamat): Alamat 

    { 
        return $this->terkunci($pemilik, function (Collection $punya) use ($idAlamat): Alamat { 
            // Dicari di antara alamat MILIK pengguna ini. Nomor alamat orang lain tidak ditemukan. 
            $terpilih = $punya->firstWhere('id', $idAlamat) ?? abort(404); 
  
            foreach ($punya as $alamat) { 
                $alamat->forceFill(['utama' => $alamat->is($terpilih)])->save(); 
            } 
  
            return $terpilih; 
        }); 
    } 
  
    public function hapus(Pengguna $pemilik, int $idAlamat): void 
    { 
        $this->terkunci($pemilik, function (Collection $punya) use ($idAlamat): void { 
            $dihapus = $punya->firstWhere('id', $idAlamat) ?? abort(404); 
            $dihapus->delete(); 
  
            // Alamat utama yang dihapus digantikan alamat tertua yang tersisa. 
            $pengganti = $punya->first(fn (Alamat $alamat) => ! $alamat->is($dihapus)); 
  
            if ($dihapus->utama && $pengganti !== null) { 
                $pengganti->forceFill(['utama' => true])->save(); 
            } 
        }); 
    } 
  
    /** 
     * @template T 
     * 
     * @param  Closure(Collection<int, Alamat>): T  $kerja  menerima alamat pemilik, urut nomor 
     * @return T 
     */ 
    private function terkunci(Pengguna $pemilik, Closure $kerja): mixed 
    { 
        return DB::transaction(function () use ($pemilik, $kerja) { 
            Pengguna::query()->whereKey($pemilik->id)->lockForUpdate()->firstOrFail(); 
  
            // Dibaca SETELAH gembok didapat, dan dengan kunci: pembacaan biasa di dalam transaksi 
            // MySQL dapat mengembalikan keadaan sebelum permintaan lain selesai menulis. 
            $punya = Alamat::query()->milik($pemilik)->orderBy('id')->lockForUpdate()->get(); 
  
            return $kerja($punya); 
        }); 
    } 
} 
 
Seluruh isi kelas ini bergantung pada metode privat terkunci(). Metode itu mengunci baris pengguna lebih dulu. 
Baris pengguna dipilih karena selalu ada: pembeli baru belum memiliki satu alamat pun untuk dikunci, sedangkan 
dua permintaan pertamanya justru yang paling rawan. Setelah gembok itu didapat, alamat miliknya dibaca dengan 
lockForUpdate() juga, mengikuti aturan kunci dulu, baca kemudian. 
Ketiga metode publik menerima daftar alamat itu dari terkunci() dan tidak pernah membaca basis data sendiri. 
Keputusan "sudah ada alamat atau belum" dan "sudah lima atau belum" karenanya selalu diambil dari keadaan 
terkini. 
app/Http/Requests/AlamatRequest.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Requests; 
  
use Illuminate\Foundation\Http\FormRequest; 
  
/** Tidak ada pengguna_id maupun utama: keduanya ditentukan server. */ 
final class AlamatRequest extends FormRequest 
{ 
    public function authorize(): bool 
    { 
        return true; 

    } 
  
    public function rules(): array 
    { 
        return [ 
            'label' => ['required', 'string', 'min:2', 'max:30'], 
            'nama_penerima' => ['required', 'string', 'min:3', 'max:80'], 
            'telepon' => ['required', 'string', 'regex:/\A0[0-9]{8,14}\z/'], 
            'jalan' => ['required', 'string', 'min:5', 'max:200'], 
            'kota' => ['required', 'string', 'min:2', 'max:60'], 
            'kode_pos' => ['required', 'string', 'regex:/\A[0-9]{5}\z/'], 
        ]; 
    } 
  
    public function messages(): array 
    { 
        return [ 
            'telepon.regex' => 'Nomor telepon diawali 0 dan berisi 9 sampai 15 angka.', 
            'kode_pos.regex' => 'Kode pos berisi tepat 5 angka.', 
        ]; 
    } 
} 
 
app/Http/Controllers/AlamatController.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Controllers; 
  
use App\Http\Requests\AlamatRequest; 
use App\Http\Resources\AlamatResource; 
use App\Models\Alamat; 
use App\Services\BukuAlamat; 
use Illuminate\Http\JsonResponse; 
use Illuminate\Http\Request; 
  
final class AlamatController extends Controller 
{ 
    public function __construct(private readonly BukuAlamat $buku) {} 
  
    /** GET /alamat: alamat milik pengguna sesi, alamat utama lebih dulu. */ 
    public function index(Request $request): JsonResponse 
    { 
        $alamat = Alamat::query() 
            ->milik($request->user()) 
            ->orderByDesc('utama') 
            ->orderBy('id') 
            ->get(); 
  
        return $this->sukses(AlamatResource::collection($alamat)); 
    } 
  
    /** POST /alamat */ 
    public function store(AlamatRequest $request): JsonResponse 
    { 
        $alamat = $this->buku->tambah($request->user(), $request->validated()); 
  
        return $this->sukses(AlamatResource::make($alamat), 201); 
    } 
  
    /** PUT /alamat/{id}/utama: idempoten, mengulanginya tidak mengubah apa pun. */ 
    public function utama(Request $request, int $id): JsonResponse 
    { 
        $alamat = $this->buku->jadikanUtama($request->user(), $id); 
  
        return $this->sukses(AlamatResource::make($alamat)); 
    } 
  
    /** DELETE /alamat/{id}: pesanan lama tidak terpengaruh, karena menyimpan salinannya sendiri. */ 
    public function destroy(Request $request, int $id): JsonResponse 
    { 
        $this->buku->hapus($request->user(), $id); 
  
        return $this->sukses(); 

    } 
} 
 
routes/api/identitas.php — perubahan B1 
     ->group(function (): void { 
         Route::get('/', [AlamatController::class, 'index'])->name('index'); 
+        Route::post('/', [AlamatController::class, 'store'])->name('store'); 
+        Route::put('{id}/utama', [AlamatController::class, 'utama'])->name('utama'); 
+        Route::delete('{id}', [AlamatController::class, 'destroy'])->name('destroy'); 
     }); 
 
tests/Feature/Sistem/matriks/identitas.php — perubahan B1 
  
     'GET api/v1/alamat' => ['pembeli'], 
+    'POST api/v1/alamat' => ['pembeli'], 
+    'PUT api/v1/alamat/{id}/utama' => ['pembeli'], 
+    'DELETE api/v1/alamat/{id}' => ['pembeli'], 
 ]; 
 
PUT /alamat/{id}/utama dan DELETE /alamat/{id} tidak memerlukan Idempotency-Key: menjadikan alamat 
yang sudah utama sebagai utama tidak mengubah apa pun, dan menghapus alamat yang sudah terhapus dijawab 
404. POST /alamat dicatat Sekar pada Langkah 11 sebagai rute tanpa kunci dengan alasan tertulis: tidak 
menyentuh uang maupun stok, dan jumlah alamat dibatasi. 
7.2 Uji. 
tests/Feature/Identitas/AlamatTest.php — uji yang ditambahkan B1 (sebagian) 
it('menjadikan alamat pertama sebagai alamat utama, dan alamat berikutnya bukan', function () { 
    $this->sebagai($this->andi); 
  
    $this->postJson('/api/v1/alamat', dataAlamat()) 
        ->assertCreated() 
        ->assertJsonPath('data.utama', true); 
    $this->postJson('/api/v1/alamat', dataAlamat(['label' => 'Kantor'])) 
        ->assertCreated() 
        ->assertJsonPath('data.utama', false); 
  
    expect(Alamat::query()->milik($this->andi)->where('utama', true)->count())->toBe(1); 
}); 
  
it('mengabaikan pemilik dan tanda utama kiriman klien', function () { 
    $orangLain = Pengguna::factory()->pembeli()->create(); 
    Alamat::factory()->for($this->andi, 'pengguna')->create(); 
  
    $this->sebagai($this->andi) 
        ->postJson('/api/v1/alamat', dataAlamat(['pengguna_id' => $orangLain->id, 'utama' => true])) 
        ->assertCreated() 
        ->assertJsonPath('data.utama', false); 
  
    expect(Alamat::query()->milik($orangLain)->count())->toBe(0); 
}); 
 
tests/Feature/Identitas/AlamatTest.php — batas jumlah, memindahkan utama, alamat orang lain 
it('membatasi jumlah alamat', function () { 
    config(['lapak.transaksi.alamat_maksimum' => 2]); 
    $this->sebagai($this->andi); 
  
    $this->postJson('/api/v1/alamat', dataAlamat())->assertCreated(); 
    $this->postJson('/api/v1/alamat', dataAlamat(['label' => 'Kantor']))->assertCreated(); 
    $this->postJson('/api/v1/alamat', dataAlamat(['label' => 'Kos'])) 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'ALAMAT_PENUH'); 
}); 
  
it('memindahkan tanda utama sehingga selalu tepat satu', function () { 

    $rumah = Alamat::factory()->for($this->andi, 'pengguna')->create(); 
    $kantor = Alamat::factory()->cadangan()->for($this->andi, 'pengguna')->create(); 
    $this->sebagai($this->andi); 
  
    $this->putJson("/api/v1/alamat/{$kantor->id}/utama") 
        ->assertOk() 
        ->assertJsonPath('data.id', $kantor->id) 
        ->assertJsonPath('data.utama', true); 
    $this->putJson("/api/v1/alamat/{$kantor->id}/utama")->assertOk();   // diulang: hasilnya sama 
  
    expect($rumah->refresh()->utama)->toBeFalse() 
        ->and($kantor->refresh()->utama)->toBeTrue(); 
}); 
  
it('menjawab 404 untuk alamat milik orang lain dan tidak mengubahnya', function () { 
    Alamat::factory()->for($this->andi, 'pengguna')->create(); 
    $milikLain = Alamat::factory()->cadangan()->create(); 
    $this->sebagai($this->andi); 
  
    $this->putJson("/api/v1/alamat/{$milikLain->id}/utama")->assertNotFound(); 
    $this->deleteJson("/api/v1/alamat/{$milikLain->id}")->assertNotFound(); 
    $this->deleteJson('/api/v1/alamat/abc')->assertNotFound(); 
  
    expect($milikLain->refresh()->utama)->toBeFalse() 
        ->and(Alamat::query()->milik($this->andi)->where('utama', true)->count())->toBe(1); 
}); 
 
7.3 Skenario balapan. 
app/Balapan/AlamatPertama.php 
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
  
 
Terminal — Bambang 
php artisan migrate:fresh --seed 
php artisan lapak:balapan alamat-pertama 
 
Keluaran — varian tanpa kunci, percobaan pertama 
alamat-pertama: Pembeli baru menyimpan alamat dari banyak tab: satu utama, tidak melebihi batas 
+-------+---------------+ 
| Hasil | Jumlah proses | 
+-------+---------------+ 
| 201   | 8             | 
+-------+---------------+ 
PELANGGARAN: Ada 7 alamat utama, seharusnya tepat 1. 
PELANGGARAN: Tersimpan 8 alamat, melebihi batas 5. 
 
Delapan tab, delapan jawaban 201. Tujuh di antaranya melihat "belum ada alamat" lalu menjadikan alamatnya 
utama, dan tidak satu pun melihat batas lima terlampaui. Pada percobaan kedua hasilnya lain: enam alamat 
tersimpan dan dua ditolak. Balapan tidak memberi hasil yang sama setiap kali; yang tetap adalah aturannya 
dilanggar. 

Keluaran — versi akhir 
alamat-pertama: Pembeli baru menyimpan alamat dari banyak tab: satu utama, tidak melebihi batas 
+------------------+---------------+ 
| Hasil            | Jumlah proses | 
+------------------+---------------+ 
| 201              | 5             | 
| 422 ALAMAT_PENUH | 3             | 
+------------------+---------------+ 
LULUS: 8 proses serentak, tidak ada aturan yang dilanggar. 
 
Dengan kunci, kedelapan tab antre. Lima yang pertama diterima, tiga sisanya mendapati batas sudah tercapai, dan 
tepat satu alamat menjadi utama. 
Terminal — Bambang 
./vendor/bin/pint 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git add app/Services/BukuAlamat.php app/Http/Requests/AlamatRequest.php \ 
        app/Http/Controllers/AlamatController.php routes/api/identitas.php \ 
        tests/Feature/Sistem/matriks/identitas.php 
git commit -m "feat: buku alamat mengunci baris pengguna sebelum menghitung" 
git add tests/Feature/Identitas/AlamatTest.php app/Balapan/AlamatPertama.php 
git commit -m "test: uji buku alamat dan skenario balapan alamat-pertama" 
git push -u origin sesi-4/alamat-bambang 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 332 uji lulus. 
Langkah 8 — B2: Checkout Atomik dan Pembatalan Otomatis (Rani) 
Cabang: sesi-4/checkout-rani. Inilah pusat sesi. Seluruh kontrak jendela A bertemu di satu metode. 
8.1 Penyusun belanja menerima produk yang sudah di tangan. PenyusunBelanja dari Sesi 1 mengambil 
produknya sendiri dengan SELECT biasa. Itu benar untuk simulasi dan keranjang, tetapi checkout harus menghitung 
dari baris yang sedang dikunci PenjagaStok. Rani tidak menulis penghitung kedua. Ia menambahkan satu 
parameter. 
app/Services/PenyusunBelanja.php — perubahan B2 
 use App\Models\Pengguna; 
 use App\Models\Produk; 
+use Illuminate\Support\Collection; 
  
 /** 
   ... 
      * Dipakai keranjang: isi keranjang bisa menjadi basi, dan membukanya tidak boleh gagal. 
      * 
+     * Bila $diTangan diisi, tidak ada kueri produk di sini. Harga dan stok dihitung dari baris 
+     * yang sudah diambil pemanggil lewat PenjagaStok, yaitu baris yang sedang dikuncinya. 
+     * 
      * @param  list<array{produk_id: int, kuantitas: int}>  $butir 
      * @param  Pengguna|null  $pembeli  bila diisi, produk dari toko miliknya ikut ditandai (LP-5) 
+     * @param  Collection<int, Produk>|null  $diTangan  lihat keterangan di atas 
      * @return array{0: list<RincianToko>, 1: list<ButirBermasalah>} 
      */ 
-    public function pilah(array $butir, ?Pengguna $pembeli = null): array 
-    { 
+    public function pilah( 
+        array $butir, 
+        ?Pengguna $pembeli = null, 
+        ?Collection $diTangan = null, 
+    ): array { 
         $diminta = collect($butir)->pluck('kuantitas', 'produk_id'); 
  
-        $produk = Produk::query() 
-            ->tampil() 
-            ->with('toko') 
-            ->whereIn('id', $diminta->keys()) 
-            ->get() 
-            ->keyBy('id'); 

+        $produk = $diTangan !== null 
+            ? $diTangan->filter(fn (Produk $barang) => $barang->sedangTampil())->keyBy('id') 
+            : Produk::query() 
+                ->tampil() 
+                ->with('toko') 
+                ->whereIn('id', $diminta->keys()) 
+                ->get() 
+                ->keyBy('id'); 
  
         $baris = []; 
   ... 
     /** 
      * Versi tegas: satu butir bermasalah menggagalkan seluruhnya. 
-     * Dipakai simulasi sekarang dan checkout pada Sesi 4. 
+     * Dipakai simulasi belanja, pengisian keranjang, dan checkout. 
      * 
      * @param  list<array{produk_id: int, kuantitas: int}>  $butir 
+     * @param  Collection<int, Produk>|null  $diTangan  lihat pilah() 
      * @return list<RincianToko> 
      */ 
-    public function susun(array $butir, ?Pengguna $pembeli = null): array 
-    { 
-        [$rincian, $bermasalah] = $this->pilah($butir, $pembeli); 
+    public function susun( 
+        array $butir, 
+        ?Pengguna $pembeli = null, 
+        ?Collection $diTangan = null, 
+    ): array { 
+        [$rincian, $bermasalah] = $this->pilah($butir, $pembeli, $diTangan); 
  
         $hilang = []; 
 
Rumus harga, ongkir, dan total tetap ditulis satu kali, di PenghitungBelanja. Simulasi belanja, keranjang, dan 
checkout melewati rumus yang sama; yang berbeda hanya dari mana baris produknya berasal. 
8.2 Layanan checkout. 
app/Services/LayananCheckout.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Domain\Belanja\PenghitungBelanja; 
use App\Domain\Belanja\RincianToko; 
use App\Domain\Pesanan\StatusPesanan; 
use App\Exceptions\GalatDomain; 
use App\Models\Alamat; 
use App\Models\ButirPesanan; 
use App\Models\Checkout; 
use App\Models\Keranjang; 
use App\Models\Pengguna; 
use App\Models\Pesanan; 
use App\Models\Produk; 
use App\Models\Sesi; 
use Illuminate\Support\Collection; 
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Str; 
  
/** 
 * Mengubah isi keranjang menjadi pesanan. Seluruh langkahnya berada dalam SATU transaksi: 
 * stok berkurang, pesanan lahir, tagihan terbit, dan keranjang kosong terjadi bersama, 
 * atau tidak satu pun terjadi. 
 * 
 * Urutan kunci: keranjang, lalu produk urut nomor. Urutan ini tidak boleh dibalik. 
 */ 
final class LayananCheckout 
{ 
    public function __construct( 
        private readonly PenjagaStok $stok, 
        private readonly PenyusunBelanja $penyusun, 
        private readonly PenjagaTransaksi $penjaga, 
        private readonly LayananPembayaran $pembayaran, 
    ) {} 
  

    public function proses(Pengguna $pembeli, Alamat $alamat, Sesi $sesi): Checkout 
    { 
        return DB::transaction(function () use ($pembeli, $alamat, $sesi): Checkout { 
            // 1. Kunci keranjang. Dua checkout atas keranjang yang sama antre di baris ini; 
            //    yang kedua mendapati keranjangnya sudah kosong. 
            $keranjang = Keranjang::query() 
                ->where('pengguna_id', $pembeli->id) 
                ->lockForUpdate() 
                ->first(); 
  
            // Isinya dibaca SETELAH kunci didapat, dan dengan kunci pula. 
            $butir = $keranjang?->butir()->orderBy('id')->lockForUpdate()->get() ?? collect(); 
  
            if ($butir->isEmpty()) { 
                throw new GalatDomain('KERANJANG_KOSONG', 'Keranjang Anda kosong.', 422); 
            } 
  
            // 2. Ambil produk lewat PenjagaStok, lalu hitung dari baris yang diambilnya. 
            //    Klien tidak mengirim harga, kuantitas, maupun total (LP-6). 
            $produk = $this->stok->ambil($butir->pluck('produk_id')->all()); 
            $diminta = $butir 
                ->map(fn ($b) => ['produk_id' => $b->produk_id, 'kuantitas' => $b->kuantitas]) 
                ->all(); 
  
            // Satu butir bermasalah menggagalkan seluruh checkout (LP-4, LP-5). 
            $rincian = $this->penyusun->susun($diminta, $pembeli, $produk); 
            $total = PenghitungBelanja::totalBayar($rincian); 
  
            // 3. Nilai besar menuntut sesi segar (LP-15). Gagal di sini membatalkan semuanya. 
            $this->penjaga->pastikanLayak($sesi, $total); 
  
            // 4. Tulis: checkout, satu pesanan per toko (LP-7), butir berharga beku, stok. 
            $checkout = new Checkout; 
            $checkout->forceFill([ 
                'kode' => (string) Str::ulid(), 
                'pembeli_id' => $pembeli->id, 
                'total_rupiah' => $total->rupiah, 
                'pengenal_sesi' => $sesi->pengenal, 
            ])->save(); 
  
            foreach ($rincian as $rincianToko) { 
                $this->buatPesanan($checkout, $rincianToko, $alamat, $produk); 
            } 
  
            // 5. Satu tagihan untuk seluruh pesanan, lalu keranjang dikosongkan. 
            $this->pembayaran->buat($checkout); 
            $keranjang->butir()->delete(); 
  
            return $checkout; 
        }); 
    } 
  
    /** 
     * @param  Collection<int, Produk>  $produk  hasil PenjagaStok::ambil() 
     */ 
    private function buatPesanan( 
        Checkout $checkout, 
        RincianToko $rincian, 
        Alamat $alamat, 
        Collection $produk, 
    ): void { 
        $pesanan = new Pesanan; 
        $pesanan->forceFill([ 
            'checkout_id' => $checkout->id, 
            'pembeli_id' => $checkout->pembeli_id, 
            'toko_id' => $rincian->tokoId, 
            'status' => StatusPesanan::MenungguPembayaran, 
            'subtotal_rupiah' => $rincian->subtotal->rupiah, 
            'ongkir_rupiah' => $rincian->ongkir->rupiah, 
            'total_rupiah' => $rincian->total()->rupiah, 
            // Salinan, bukan penunjuk: lihat Alamat::potret(). 
            'alamat_kirim' => $alamat->potret(), 
        ])->save(); 
  
        foreach ($rincian->baris as $baris) { 
            $this->stok->kurangi($produk[$baris->produkId], $baris->kuantitas); 
  
            $butir = new ButirPesanan; 
            $butir->forceFill([ 

                'pesanan_id' => $pesanan->id, 
                'produk_id' => $baris->produkId, 
                // LP-6: nama dan harga dibekukan pada saat ini. 
                'nama_produk' => $baris->namaProduk, 
                'harga_rupiah' => $baris->hargaSatuan->rupiah, 
                'kuantitas' => $baris->kuantitas, 
                'subtotal_rupiah' => $baris->subtotal()->rupiah, 
            ])->save(); 
        } 
    } 
} 
 
Bandingkan metode proses() dengan gambar pada D.2, baris demi baris. Lima hal yang tidak terlihat dari gambar: 
1. DB::transaction() di sini bersarang. Rute checkout memasang idempoten, sehingga transaksi terluarnya milik 
middleware. Layanan tetap membuka transaksinya sendiri agar ia benar pula ketika dipanggil dari tempat lain, 
misalnya dari uji atau dari skenario balapan. 
2. Keranjang dikunci, lalu isinya dibaca dengan kunci. Dua baris itu tidak boleh dipisahkan, dan 
lockForUpdate() pada baris kedua bukan kehati-hatian berlebih. Buktinya pada 8.6. 
3. Produk diambil sekali, lewat PenjagaStok, lalu diserahkan ke penyusun. Harga yang dipakai menghitung, stok 
yang diperiksa, dan baris yang dikurangi adalah baris yang sama. 
4. pastikanLayak() dipanggil setelah total diketahui dan sebelum apa pun ditulis. Bila sesi tidak segar, galatnya 
membatalkan transaksi yang belum menulis apa-apa selain catatan kunci idempotensi, dan catatan itu ikut 
batal. 
5. Tagihan dibuat di dalam transaksi yang sama. Tidak ada checkout tanpa tagihan, dan tidak ada tagihan tanpa 
checkout. 
8.3 Pintu HTTP. 
app/Http/Requests/CheckoutRequest.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Requests; 
  
use Illuminate\Foundation\Http\FormRequest; 
use Illuminate\Validation\Rule; 
  
/** 
 * Checkout hanya menerima SATU hal dari klien: alamat mana yang dipakai. 
 * Isi keranjang, harga, ongkir, dan total seluruhnya dibaca server dari basis data (LP-6). 
 */ 
final class CheckoutRequest extends FormRequest 
{ 
    public function authorize(): bool 
    { 
        return true; 
    } 
  
    public function rules(): array 
    { 
        return [ 
            'alamat_id' => [ 
                'required', 'integer', 
                // Alamat orang lain dan alamat yang tidak ada dijawab dengan pesan yang sama. 
                Rule::exists('alamat', 'id')->where('pengguna_id', $this->user()->id), 
            ], 
        ]; 
    } 
  
    public function messages(): array 
    { 
        return ['alamat_id.exists' => 'Alamat tidak ditemukan.']; 
    } 
} 
 

app/Http/Controllers/CheckoutController.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Controllers; 
  
use App\Http\Requests\CheckoutRequest; 
use App\Http\Resources\CheckoutResource; 
use App\Models\Alamat; 
use App\Models\Sesi; 
use App\Services\LayananCheckout; 
use Illuminate\Http\JsonResponse; 
  
final class CheckoutController extends Controller 
{ 
    /** 
     * POST /checkout. Rutenya dipasangi idempoten: seluruh isi metode ini berjalan di dalam 
     * transaksi milik middleware itu, bersama catatan Idempotency-Key-nya (LP-11). 
     */ 
    public function __invoke(CheckoutRequest $request, LayananCheckout $layanan): JsonResponse 
    { 
        $pembeli = $request->user(); 
        $alamat = Alamat::query()->milik($pembeli)->findOrFail($request->integer('alamat_id')); 
  
        $checkout = $layanan->proses($pembeli, $alamat, Sesi::dariPermintaan($request)); 
  
        return $this->sukses( 
            CheckoutResource::make($checkout->load(['pesanan.toko', 'pesanan.butir'])), 
            201, 
        ); 
    } 
} 
 
app/Http/Resources/CheckoutResource.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Resources; 
  
use App\Domain\Uang; 
use App\Models\Checkout; 
use App\Services\LayananPembayaran; 
use Illuminate\Http\Request; 
use Illuminate\Http\Resources\Json\JsonResource; 
  
/** 
 * Hasil sebuah checkout: satu tagihan dan satu pesanan per toko (LP-7). 
 * Relasi pesanan beserta toko dan butirnya harus sudah dimuat. 
 * 
 * @mixin Checkout 
 */ 
final class CheckoutResource extends JsonResource 
{ 
    public function toArray(Request $request): array 
    { 
        $total = Uang::dari($this->total_rupiah); 
        $pembayaran = app(LayananPembayaran::class)->untuk($this->resource); 
  
        return [ 
            'kode' => $this->kode, 
            'total' => ['rupiah' => $total, 'tampilan' => $total->format()], 
            'pembayaran' => PembayaranResource::make($pembayaran), 
            'pesanan' => PesananResource::collection($this->pesanan), 
        ]; 
    } 
} 
 
app/Http/Resources/PesananResource.php 
<?php 
  

declare(strict_types=1); 
  
namespace App\Http\Resources; 
  
use App\Domain\Uang; 
use App\Models\ButirPesanan; 
use App\Models\Pesanan; 
use Illuminate\Http\Request; 
use Illuminate\Http\Resources\Json\JsonResource; 
  
/** 
 * Pesanan seperti yang dilihat PEMBELINYA. Relasi toko dan butir harus sudah dimuat. 
 * 
 * @mixin Pesanan 
 */ 
final class PesananResource extends JsonResource 
{ 
    /** Nomor urut internal pesanan, checkout, dan pembeli tidak dikeluarkan. */ 
    public function toArray(Request $request): array 
    { 
        $total = Uang::dari($this->total_rupiah); 
  
        return [ 
            'nomor' => $this->nomor, 
            'status' => $this->status->value, 
            'toko' => ['nama' => $this->toko->nama, 'slug' => $this->toko->slug], 
            'butir' => $this->butir->map(fn (ButirPesanan $butir) => [ 
                'produk_id' => $butir->produk_id, 
                'nama' => $butir->nama_produk, 
                'harga_satuan' => $butir->harga_rupiah, 
                'kuantitas' => $butir->kuantitas, 
                'subtotal' => $butir->subtotal_rupiah, 
            ])->all(), 
            'subtotal' => $this->subtotal_rupiah, 
            'ongkir' => $this->ongkir_rupiah, 
            'total' => ['rupiah' => $total, 'tampilan' => $total->format()], 
            'alamat_kirim' => $this->alamat_kirim, 
            'dibuat_pada' => $this->created_at->toIso8601String(), 
            'dibayar_pada' => $this->dibayar_pada?->toIso8601String(), 
            'dibatalkan_pada' => $this->dibatalkan_pada?->toIso8601String(), 
            'alasan_batal' => $this->alasan_batal, 
        ]; 
    } 
} 
 
app/Http/Controllers/PesananSayaController.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Controllers; 
  
use App\Http\Resources\PembayaranResource; 
use App\Http\Resources\PesananResource; 
use App\Models\Pesanan; 
use App\Services\LayananPembayaran; 
use Illuminate\Http\JsonResponse; 
use Illuminate\Http\Request; 
  
/** Pesanan milik pembeli yang sedang masuk. Seluruh kuerinya berangkat dari scopeMilikPembeli. */ 
final class PesananSayaController extends Controller 
{ 
    /** GET /pesanan: terbaru lebih dulu. */ 
    public function index(Request $request): JsonResponse 
    { 
        $halaman = Pesanan::query() 
            ->milikPembeli($request->user()) 
            ->with(['toko', 'butir']) 
            ->orderByDesc('id') 
            ->paginate(config('lapak.katalog.per_halaman_bawaan'), ['*'], 'halaman'); 
  
        return $this->berhalaman($halaman, PesananResource::class); 
    } 
  
    /** 
     * GET /pesanan/{nomor}. Nomor pesanan orang lain dijawab 404, sama seperti nomor yang tidak 

     * pernah ada. Nomor pesanan berurutan dan mudah ditebak; yang menjaganya adalah kueri ini. 
     */ 
    public function show( 
        Request $request, 
        LayananPembayaran $pembayaran, 
        string $nomor, 
    ): JsonResponse { 
        $pesanan = Pesanan::query() 
            ->milikPembeli($request->user()) 
            ->with(['toko', 'butir', 'checkout']) 
            ->where('nomor', $nomor) 
            ->firstOrFail(); 
  
        return $this->sukses([ 
            'pesanan' => PesananResource::make($pesanan), 
            'pembayaran' => PembayaranResource::make($pembayaran->untuk($pesanan->checkout)), 
        ]); 
    } 
} 
 
Titipan kedua Sesi 3 lunas pada controller terakhir. Nomor pesanan berurutan dan mudah ditebak. Yang 
menjaganya bukan kerahasiaan nomor, melainkan milikPembeli() pada setiap kueri: nomor pesanan orang lain 
dijawab 404, sama seperti nomor yang tidak pernah ada. 
routes/api/belanja.php — perubahan B2 
 <?php 
  
+use App\Http\Controllers\CheckoutController; 
 use App\Http\Controllers\KeranjangController; 
+use App\Http\Controllers\PesananSayaController; 
 use App\Http\Controllers\SimulasiBelanjaController; 
 use Illuminate\Support\Facades\Route; 
   ... 
 Route::post('simulasi-belanja', SimulasiBelanjaController::class)->name('belanja.simulasi'); 
  
-// Keranjang selalu milik pengguna yang sedang masuk. Checkout menyusul pada Sesi 4. 
+// Keranjang selalu milik pengguna yang sedang masuk. 
 // Hanya pemegang izin keranjang.kelola: akun staf tidak berbelanja memakai akun dinasnya. 
 Route::middleware(['auth:sesi', 'izin:keranjang.kelola']) 
   ... 
         Route::delete('butir/{produkId}', [KeranjangController::class, 'hapusButir'])->name('hapus'); 
     }); 
+ 
+// Checkout dan pesanan milik pembeli yang sedang masuk. 
+Route::middleware(['auth:sesi', 'izin:pesanan.buat'])->group(function (): void { 
+    // Urutan: batas laju per sesi dulu, baru idempotensi. Permintaan yang ditolak pembatas laju 
+    // tidak sempat membuka transaksi. 
+    Route::post('checkout', CheckoutController::class) 
+        ->middleware(['throttle:transaksi', 'idempoten']) 
+        ->name('checkout'); 
+ 
+    Route::get('pesanan', [PesananSayaController::class, 'index'])->name('pesanan.index'); 
+    // Sengaja tanpa pola pada {nomor}: alamat yang bentuknya salah tetap melewati kunci sesi dan 
+    // izin lebih dulu, lalu dijawab 404 oleh kueri berlingkup, sama seperti nomor orang lain. 
+    Route::get('pesanan/{nomor}', [PesananSayaController::class, 'show'])->name('pesanan.show'); 
+}); 
 
Urutan middleware pada rute checkout disengaja: kunci sesi, izin, batas laju, barulah idempoten. Permintaan yang 
ditolak pembatas laju tidak sempat membuka transaksi. 
tests/Feature/Sistem/matriks/belanja.php — perubahan B2 
     'PUT api/v1/keranjang/butir/{produkId}' => ['pembeli'], 
     'DELETE api/v1/keranjang/butir/{produkId}' => ['pembeli'], 
+ 
+    'POST api/v1/checkout' => ['pembeli'], 
+    'GET api/v1/pesanan' => ['pembeli'], 
+    'GET api/v1/pesanan/{nomor}' => ['pembeli'], 
 ]; 
 
8.4 Pembatalan otomatis. 

app/Services/PembatalanOtomatis.php — versi B2 
<?php 
  
declare(strict_types=1); 
  
namespace App\Services; 
  
use App\Domain\Pesanan\PelakuPesanan; 
use App\Domain\Pesanan\StatusPesanan; 
use App\Models\Checkout; 
use App\Models\Pembayaran; 
  
/** 
 * LP-10: checkout yang tidak dibayar sampai batas waktunya dibatalkan dan stoknya kembali. 
 * 
 * Tanpa kelas ini, siapa pun dapat mengosongkan stok seluruh toko tanpa membayar sepeser pun: 
 * cukup checkout, lalu tidak pernah melunasinya. 
 */ 
final class PembatalanOtomatis 
{ 
    public const ALASAN = 'Tidak dibayar sampai batas waktu'; 
  
    public function __construct( 
        private readonly GembokCheckout $gembok, 
        private readonly LayananPembayaran $pembayaran, 
        private readonly MesinStatusPesanan $mesin, 
        private readonly PenjagaStok $stok, 
    ) {} 
  
    /** @return int jumlah checkout yang dibatalkan */ 
    public function jalankan(): int 
    { 
        $dibatalkan = 0; 
  
        // Satu transaksi per checkout: kegagalan pada satu checkout tidak menahan yang lain. 
        foreach (Pembayaran::query()->lewatBatas()->orderBy('id')->pluck('checkout_id') as $id) { 
            $dibatalkan += $this->batalkan($id) ? 1 : 0; 
        } 
  
        return $dibatalkan; 
    } 
  
    public function batalkan(int $idCheckout): bool 
    { 
        return $this->gembok->dalam($idCheckout, function (Checkout $checkout): bool { 
            // Di bawah gembok, tagihannya diperiksa ulang. Bila pembayaran masuk sesaat sebelum 
            // gembok didapat, tidak ada yang dibatalkan. 
            if (! $this->pembayaran->tandaiKedaluwarsa($checkout)) { 
                return false; 
            } 
  
            foreach ($checkout->pesanan()->with('butir')->orderBy('id')->get() as $pesanan) { 
                $this->mesin->pindahkan( 
                    $pesanan, StatusPesanan::Dibatalkan, PelakuPesanan::Sistem, self::ALASAN, 
                ); 
  
                foreach ($pesanan->butir as $butir) { 
                    $this->stok->kembalikan($butir->produk_id, $butir->kuantitas); 
                } 
            } 
  
            return true; 
        }); 
    } 
} 
 
app/Console/Commands/BatalkanKedaluwarsa.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Console\Commands; 
  
use App\Services\PembatalanOtomatis; 

use Illuminate\Console\Command; 
  
final class BatalkanKedaluwarsa extends Command 
{ 
    protected $signature = 'lapak:batalkan-kedaluwarsa'; 
  
    protected $description = 'Membatalkan checkout yang tidak dibayar sampai batas waktu (LP-10)'; 
  
    public function handle(PembatalanOtomatis $pembatalan): int 
    { 
        $jumlah = $pembatalan->jalankan(); 
  
        $this->info("{$jumlah} checkout dibatalkan dan stoknya dikembalikan."); 
  
        return self::SUCCESS; 
    } 
} 
 
routes/console.php — perubahan B2 
 // Sesi yang sudah lama mati dibuang setiap dini hari. 
 Schedule::command('lapak:pangkas-sesi')->dailyAt('03:15'); 
+ 
+// LP-10: checkout yang lewat batas bayar dibatalkan dan stoknya dikembalikan. 
+Schedule::command('lapak:batalkan-kedaluwarsa')->everyTenMinutes()->withoutOverlapping(); 
 
jalankan() memilih tagihan yang lewat batas tanpa kunci, lalu memproses setiap checkout dalam transaksinya 
sendiri. Di dalam gembok, tandaiKedaluwarsa() milik Dimas memeriksa ulang tagihannya. Bila pembayaran 
masuk di antara kedua saat itu, metode tersebut mengembalikan false dan tidak ada yang dibatalkan. 
Versi ini lulus seluruh uji Rani, lulus tinjauan Bagas, dan di-merge. Ia menyimpan satu kesalahan urutan kunci yang 
baru ditemukan pada Langkah 12. Coba temukan sendiri sebelum sampai ke sana: bandingkan urutan baris yang 
disentuh metode batalkan() dengan tangga pada D.3. 
8.5 Uji. Berkas uji checkout berisi 19 uji dan tersedia utuh pada berkas pendamping. Empat cuplikan berikut 
memuat yang paling khas sesi ini. 
tests/Feature/Belanja/CheckoutTest.php — keadaan awal, penolakan, satu pesanan per toko, stok dan keranjang 
<?php 
  
use App\Domain\Pesanan\PenomorPesanan; 
use App\Domain\Pesanan\StatusPesanan; 
use App\Enums\StatusPembayaran; 
use App\Enums\StatusToko; 
use App\Models\Alamat; 
use App\Models\ButirKeranjang; 
use App\Models\ButirPesanan; 
use App\Models\Checkout; 
use App\Models\Keranjang; 
use App\Models\KunciIdempotensi; 
use App\Models\Pembayaran; 
use App\Models\Pengguna; 
use App\Models\Pesanan; 
use App\Models\Produk; 
use App\Models\Toko; 
use App\Services\LayananKeranjang; 
use Illuminate\Support\Facades\DB; 
  
/* 
| Keadaan awal setiap uji: Andi memiliki alamat dan keranjang berisi dua toko. 
|   Batik Bu Sri   : 2 x Kain Batik Cap @ 185.000, ongkir 12.000  -> 382.000 
|   Oleh-Oleh Wulan: 3 x Intip Goreng  @  32.500, ongkir 10.000  -> 107.500 
|                                                       total bayar  489.500 
*/ 
beforeEach(function () { 
    $this->andi = Pengguna::factory()->pembeli()->create(); 
    $this->alamat = Alamat::factory()->for($this->andi, 'pengguna')->create([ 
        'nama_penerima' => 'Andi Nugroho', 'jalan' => 'Jl. Slamet Riyadi No. 12', 
    ]); 
  

    $this->sri = Pengguna::factory()->penjual()->create(); 
    $this->batik = Toko::factory()->for($this->sri, 'pemilik') 
        ->create(['nama' => 'Batik Bu Sri', 'ongkir_rupiah' => 12_000]); 
    $this->kain = Produk::factory()->for($this->batik) 
        ->create(['nama' => 'Kain Batik Cap', 'harga_rupiah' => 185_000, 'stok' => 5]); 
  
    $this->oleh = Toko::factory()->create(['nama' => 'Oleh-Oleh Wulan', 'ongkir_rupiah' => 10_000]); 
    $this->intip = Produk::factory()->for($this->oleh) 
        ->create(['nama' => 'Intip Goreng', 'harga_rupiah' => 32_500, 'stok' => 10]); 
  
    $keranjang = app(LayananKeranjang::class); 
    $keranjang->aturButir($this->andi, $this->kain->id, 2); 
    $keranjang->aturButir($this->andi, $this->intip->id, 3); 
  
    $this->checkout = fn (array $badan = [], string $kunci = 'checkout-uji-0000000001') => $this 
        ->postJson( 
            '/api/v1/checkout', 
            $badan + ['alamat_id' => $this->alamat->id], 
            ['Idempotency-Key' => $kunci], 
        ); 
}); 
  
it('menolak permintaan tanpa kunci sesi, tanpa izin, atau tanpa Idempotency-Key', function () { 
    $badan = ['alamat_id' => $this->alamat->id]; 
  
    $this->postJson('/api/v1/checkout', $badan)->assertUnauthorized(); 
  
    $this->sebagai(Pengguna::factory()->admin()->create()); 
    ($this->checkout)()->assertForbidden()->assertJsonPath('galat.kode', 'DITOLAK'); 
  
    $this->sebagai($this->andi)->postJson('/api/v1/checkout', $badan) 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'LP-11'); 
  
    expect(Checkout::count())->toBe(0); 
}); 
  
it('melahirkan satu pesanan per toko dan satu tagihan untuk semuanya (LP-7)', function () { 
    $respons = $this->sebagai($this->andi); 
    $respons = ($this->checkout)() 
        ->assertCreated() 
        ->assertJsonPath('data.total', ['rupiah' => 489_500, 'tampilan' => 'Rp 489.500']) 
        ->assertJsonPath('data.pembayaran.status', 'menunggu') 
        ->assertJsonPath('data.pembayaran.jumlah.rupiah', 489_500) 
        ->assertJsonCount(2, 'data.pesanan') 
        ->assertJsonPath('data.pesanan.0.toko.nama', 'Batik Bu Sri') 
        ->assertJsonPath('data.pesanan.0.status', 'menunggu_pembayaran') 
        ->assertJsonPath('data.pesanan.0.total.rupiah', 382_000) 
        ->assertJsonPath('data.pesanan.1.total.rupiah', 107_500); 
  
    $checkout = Checkout::sole(); 
  
    expect($checkout->total_rupiah)->toBe(489_500) 
        ->and($checkout->pengenal_sesi)->toBe($this->sesiUji->pengenal) 
        ->and(Pembayaran::sole()->jumlah_rupiah)->toBe(489_500) 
        ->and(Pesanan::count())->toBe(2) 
        ->and($respons->json('data.pesanan.0.nomor'))->toMatch(PenomorPesanan::POLA) 
        // Jumlah seluruh pesanan selalu sama dengan jumlah tagihannya. 
        ->and((int) Pesanan::sum('total_rupiah'))->toBe(489_500); 
}); 
  
it('mengurangi stok dan mengosongkan keranjang dalam langkah yang sama (LP-4)', function () { 
    $this->sebagai($this->andi); 
    ($this->checkout)()->assertCreated(); 
  
    expect($this->kain->refresh()->stok)->toBe(3) 
        ->and($this->intip->refresh()->stok)->toBe(7) 
        ->and(ButirKeranjang::count())->toBe(0); 
  
    $this->getJson('/api/v1/keranjang')->assertJsonPath('data.pesanan', []); 
}); 
 
tests/Feature/Belanja/CheckoutTest.php — angka kiriman klien diabaikan 
it('mengabaikan harga, kuantitas, total, dan pembeli kiriman klien (LP-6)', function () { 
    $orangLain = Pengguna::factory()->pembeli()->create(); 

    $this->sebagai($this->andi); 
  
    ($this->checkout)([ 
        'total' => 1, 'total_rupiah' => 1, 'pembeli_id' => $orangLain->id, 'status' => 'dibayar', 
        'butir' => [['produk_id' => $this->kain->id, 'kuantitas' => 1, 'harga_satuan' => 1]], 
    ]) 
        ->assertCreated() 
        ->assertJsonPath('data.total.rupiah', 489_500) 
        ->assertJsonPath('data.pesanan.0.status', 'menunggu_pembayaran'); 
  
    expect(Checkout::sole()->pembeli_id)->toBe($this->andi->id) 
        ->and(ButirPesanan::query()->where('produk_id', $this->kain->id)->value('kuantitas')) 
        ->toBe(2); 
}); 
 
tests/Feature/Belanja/CheckoutTest.php — semua atau tidak sama sekali 
it('membatalkan SELURUH checkout bila satu butir kehabisan stok', function () { 
    // Di antara mengisi keranjang dan menekan bayar, stok kain tinggal satu. 
    $this->kain->forceFill(['stok' => 1])->save(); 
    $this->sebagai($this->andi); 
  
    ($this->checkout)() 
        ->assertConflict() 
        ->assertJsonPath('galat.kode', 'LP-4') 
        ->assertJsonPath('galat.rincian.tersedia', 1); 
  
    // Tidak ada yang tertulis separuh: pesanan toko kedua pun tidak lahir, stoknya utuh, 
    // keranjang masih berisi, dan Idempotency-Key tidak tercatat. 
    expect(Checkout::count())->toBe(0) 
        ->and(Pesanan::count())->toBe(0) 
        ->and(Pembayaran::count())->toBe(0) 
        ->and($this->intip->refresh()->stok)->toBe(10) 
        ->and(ButirKeranjang::count())->toBe(2) 
        ->and(KunciIdempotensi::count())->toBe(0); 
}); 
  
it('tidak menyisakan apa pun bila langkah terakhir gagal', function () { 
    // Tagihan dibuat paling akhir. Saat pembuatannya gagal, checkout, kedua pesanan, butirnya, 
    // dan pengurangan stok SUDAH ditulis di dalam transaksi. Semuanya harus ikut batal. 
    Pembayaran::creating(fn () => throw new RuntimeException('Tabel pembayaran bermasalah.')); 
    $this->sebagai($this->andi); 
  
    ($this->checkout)()->assertStatus(500)->assertJsonPath('galat.kode', 'GALAT_SERVER'); 
  
    expect(Checkout::count())->toBe(0) 
        ->and(Pesanan::count())->toBe(0) 
        ->and(ButirPesanan::count())->toBe(0) 
        ->and($this->kain->refresh()->stok)->toBe(5) 
        ->and($this->intip->refresh()->stok)->toBe(10) 
        ->and(ButirKeranjang::count())->toBe(2) 
        ->and(KunciIdempotensi::count())->toBe(0); 
}); 
 
tests/Feature/Belanja/CheckoutTest.php — pengulangan, keranjang kosong, sesi segar 
it('menjawab pengulangan dengan respons yang sama dan efek satu kali (LP-11)', function () { 
    $this->sebagai($this->andi); 
  
    $pertama = ($this->checkout)()->assertCreated(); 
    $kedua = ($this->checkout)()->assertCreated()->assertHeader('Idempotency-Replayed', 'true'); 
  
    expect($kedua->json('data.kode'))->toBe($pertama->json('data.kode')) 
        ->and(Checkout::count())->toBe(1) 
        ->and(Pesanan::count())->toBe(2) 
        ->and($this->kain->refresh()->stok)->toBe(3); 
}); 
  
it('menolak checkout kedua atas keranjang yang sudah kosong walau kuncinya baru', function () { 
    $this->sebagai($this->andi); 
  
    ($this->checkout)()->assertCreated(); 
    ($this->checkout)([], 'checkout-uji-0000000002') 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'KERANJANG_KOSONG'); 

    expect(Checkout::count())->toBe(1); 
}); 
  
it('menuntut sesi segar untuk checkout bernilai besar (LP-15)', function () { 
    config(['lapak.transaksi.batas_segar_rupiah' => 400_000]); 
    $this->sebagai($this->andi, segar: false); 
  
    ($this->checkout)()->assertForbidden()->assertJsonPath('galat.kode', 'LP-15'); 
  
    expect(Checkout::count())->toBe(0) 
        ->and($this->kain->refresh()->stok)->toBe(5) 
        ->and(ButirKeranjang::count())->toBe(2); 
  
    // Setelah sesi segar, kunci idempotensi yang sama boleh dipakai lagi. 
    $this->sebagai($this->andi); 
    ($this->checkout)()->assertCreated(); 
}); 
 
Dua uji pada cuplikan ketiga saling melengkapi. Yang pertama memeriksa aturan bisnisnya: satu butir yang 
kehabisan stok menggagalkan seluruh checkout, termasuk pesanan toko lain yang stoknya cukup. Penolakan itu 
terjadi sebelum ada yang ditulis. Yang kedua adalah uji atomisitas yang sesungguhnya: pembuatan tagihan, langkah 
paling akhir, dipaksa gagal lewat sebuah pendengar peristiwa model. Pada saat itu checkout, dua pesanan, butirnya, 
dan pengurangan stok sudah ditulis di dalam transaksi. Uji memastikan semuanya ikut batal: stok kembali utuh, 
keranjang masih berisi, dan Idempotency-Key tidak tercatat. 
tests/Feature/Belanja/PembatalanOtomatisTest.php — aman diulang, dan memeriksa ulang di bawah gembok 
it('aman dijalankan berulang: stok tidak dikembalikan dua kali', function () { 
    [, , $produk] = checkoutMenunggu(['batas_bayar_pada' => now()->subMinute()]); 
  
    $this->artisan('lapak:batalkan-kedaluwarsa')->assertSuccessful(); 
    $this->artisan('lapak:batalkan-kedaluwarsa') 
        ->expectsOutputToContain('0 checkout dibatalkan') 
        ->assertSuccessful(); 
  
    expect($produk[0]->refresh()->stok)->toBe(5); 
}); 
  
it('memeriksa ulang tagihan di bawah gembok sebelum membatalkan', function () { 
    [$checkout, $tagihan, $produk] = checkoutMenunggu(['batas_bayar_pada' => now()->subMinute()]); 
  
    // Penjadwal sudah memilih checkout ini, tetapi pembayarannya masuk sesaat sebelum gembok. 
    $tagihan->forceFill(['status' => StatusPembayaran::Lunas])->save(); 
  
    expect(app(PembatalanOtomatis::class)->batalkan($checkout->id))->toBeFalse() 
        ->and($checkout->pesanan()->pluck('status')->unique()->all()) 
        ->toBe([StatusPesanan::MenungguPembayaran]) 
        ->and($produk[0]->refresh()->stok)->toBe(3); 
}); 
 
8.6 Skenario balapan, dan dua cara salah mengunci keranjang. 
app/Balapan/KeranjangGanda.php — yang dijalankan tiap proses dan yang diperiksa 
    public function jalankan(array $konteks, int $nomor): string 
    { 
        $badan = ['alamat_id' => $konteks['alamat']]; 
  
        [$status, $json] = $this->panggil('POST', '/api/v1/checkout', $badan, [ 
            'Authorization' => 'Bearer '.$konteks['kunci'], 
            'Idempotency-Key' => "tab-{$nomor}-".Str::random(16), 
        ]); 
  
        return $this->ringkas($status, $json); 
    } 
  
    public function periksa(array $konteks, array $hasil): array 
    { 
        $checkout = Checkout::query()->where('pembeli_id', $konteks['pembeli'])->count(); 

        $stok = Produk::query()->findOrFail($konteks['produk'])->stok; 
  
        $pelanggaran = []; 
        if ($checkout !== 1) { 
            $pelanggaran[] = "Lahir {$checkout} checkout dari satu keranjang, seharusnya 1."; 
        } 
        if ($stok !== self::STOK_AWAL - 2) { 
            $terjual = self::STOK_AWAL - $stok; 
            $pelanggaran[] = "Stok berkurang {$terjual}, padahal keranjang hanya berisi 2."; 
        } 
  
        return $pelanggaran; 
    } 
 
Setiap tab memakai Idempotency-Key yang berbeda. Itu disengaja: idempotensi menjaga satu permintaan yang 
diulang, bukan dua permintaan berbeda atas keranjang yang sama. Yang harus menahan skenario ini adalah kunci 
keranjang. 
Rani menjalankannya terhadap tiga versi LayananCheckout. Kedua varian salah tersedia pada folder varian/. 
Keluaran — varian B2-tanpa-kunci-keranjang: tidak ada kunci sama sekali 
keranjang-ganda: Satu keranjang di-checkout dari banyak tab, kunci berbeda: tepat satu checkout 
+-------+---------------+ 
| Hasil | Jumlah proses | 
+-------+---------------+ 
| 201   | 6             | 
+-------+---------------+ 
PELANGGARAN: Lahir 6 checkout dari satu keranjang, seharusnya 1. 
PELANGGARAN: Stok berkurang 12, padahal keranjang hanya berisi 2. 
 
Enam tab, enam checkout, dua belas barang keluar dari keranjang yang berisi dua. Pada percobaan ketiga varian 
yang sama kebetulan lulus: satu proses selesai sebelum yang lain mulai membaca. Satu kali lulus tidak membuktikan 
apa pun; jalankan skenario beberapa kali sebelum menyimpulkan. 
Keluaran — varian B2-baca-biasa: keranjang dikunci, isinya dibaca dengan SELECT biasa 
keranjang-ganda: Satu keranjang di-checkout dari banyak tab, kunci berbeda: tepat satu checkout 
+----------------------+---------------+ 
| Hasil                | Jumlah proses | 
+----------------------+---------------+ 
| 201                  | 4             | 
| 422 KERANJANG_KOSONG | 2             | 
+----------------------+---------------+ 
PELANGGARAN: Lahir 4 checkout dari satu keranjang, seharusnya 1. 
PELANGGARAN: Stok berkurang 8, padahal keranjang hanya berisi 2. 
 
Varian kedua inilah yang menipu. Baris keranjang sudah dikunci, sehingga keenam tab benar-benar antre, dan 
kodenya tampak benar bagi siapa pun yang membacanya. Tetapi setiap tab memulai transaksinya, dan mengambil 
potretnya, sebelum mendapat giliran. Ketika giliran itu tiba, SELECT biasa atas isi keranjang menjawab dari potret 
lama dan masih melihat dua barang. Inilah aturan 2 pada D.3 dalam bentuk keluaran. 
app/Services/LayananCheckout.php — beda varian baca biasa (-) dengan versi akhir (+) 
             // Isinya dibaca SETELAH kunci didapat, dan dengan kunci pula. 
-            // VARIAN: keranjang dikunci, tetapi isinya dibaca dengan SELECT biasa. 
-            $butir = $keranjang?->butir()->orderBy('id')->get() ?? collect(); 
+            $butir = $keranjang?->butir()->orderBy('id')->lockForUpdate()->get() ?? collect(); 
  
 
Keluaran — versi akhir 
keranjang-ganda: Satu keranjang di-checkout dari banyak tab, kunci berbeda: tepat satu checkout 
+----------------------+---------------+ 
| Hasil                | Jumlah proses | 
+----------------------+---------------+ 
| 201                  | 1             | 

| 422 KERANJANG_KOSONG | 5             | 
+----------------------+---------------+ 
LULUS: 6 proses serentak, tidak ada aturan yang dilanggar. 
 
Terminal — Rani 
./vendor/bin/pint 
./vendor/bin/pest 
DB_CONNECTION=mysql DB_DATABASE=lapak_uji ./vendor/bin/pest 
git add app/Services/PenyusunBelanja.php 
git commit -m "refactor: penyusun belanja menerima produk yang sudah di tangan" 
git add app/Services/LayananCheckout.php app/Http/Requests/CheckoutRequest.php \ 
        app/Http/Resources/CheckoutResource.php app/Http/Resources/PesananResource.php \ 
        app/Http/Controllers/CheckoutController.php app/Http/Controllers/PesananSayaController.php \ 
        routes/api/belanja.php tests/Feature/Sistem/matriks/belanja.php 
git commit -m "feat: checkout atomik lintas toko (LP-4, LP-6, LP-7, LP-11)" 
git add app/Services/PembatalanOtomatis.php app/Console/Commands/BatalkanKedaluwarsa.php \ 
        routes/console.php 
git commit -m "feat: pembatalan otomatis checkout yang tidak dibayar (LP-10)" 
git add tests/Feature/Belanja app/Balapan/KeranjangGanda.php 
git commit -m "test: uji checkout, pembatalan otomatis, dan skenario keranjang-ganda" 
git push -u origin sesi-4/checkout-rani 
gh pr create --base main --draft --web 
 
Hasil yang benar di cabang ini: 346 uji lulus. Checkout di cabang ini masih memakai PenjagaStok versi kontrak yang 
belum mengunci. Itu bukan urusan PR ini, dan papan sesi sudah mencatat siapa yang menutupnya. 
Langkah 9 — B3: Kunci Baris pada Stok dan Penyesuaian Stok oleh Penjual (Bagas) 
Cabang: sesi-4/kunci-stok-bagas. Dua pekerjaan: menutup lubang yang sengaja ditinggalkan pada Langkah 4, 
lalu menutup lubang lain yang sudah ada sejak Sesi 2 tanpa disadari siapa pun. 
9.1 Skenario dulu, perbaikan kemudian. Bagas menulis skenarionya sebelum menyentuh PenjagaStok. 
app/Balapan/RebutanStok.php 
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