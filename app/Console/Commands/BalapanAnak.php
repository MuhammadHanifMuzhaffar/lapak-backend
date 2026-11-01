<?php 
  
use Illuminate\Database\Migrations\Migration; 
use Illuminate\Database\Schema\Blueprint; 
use Illuminate\Support\Facades\Schema; 
  
return new class extends Migration 
{ 
    /** LP-11: ingatan atas permintaan yang berhasil, agar pengulangannya tidak berefek lagi. */ 
    public function up(): void 
    { 
        Schema::create('kunci_idempotensi', function (Blueprint $tabel) { 
            $tabel->id(); 
            $tabel->foreignId('pengguna_id')->constrained('pengguna')->cascadeOnDelete(); 
            $tabel->string('kunci', 64); 
            // "POST api/v1/checkout": kunci yang sama tidak boleh dipakai untuk pintu lain. 
            $tabel->string('rute', 120); 
            // SHA-256 atas rute dan badan permintaan. Badannya sendiri tidak disimpan. 
            $tabel->char('sidik', 64); 
            $tabel->unsignedSmallInteger('kode_status')->nullable(); 
            $tabel->longText('badan_respons')->nullable(); 
            $tabel->timestamp('created_at')->useCurrent(); 
  
            // Indeks inilah yang membuat dua permintaan kembar yang datang bersamaan antre. 
            $tabel->unique(['pengguna_id', 'kunci']); 
        }); 
    } 
  
    public function down(): void 
    { 
        Schema::dropIfExists('kunci_idempotensi'); 
    } 
}; 
 
Tiga kolom perlu dijelaskan. sidik adalah SHA-256 atas rute dan badan permintaan; dengan itu server dapat 
mengenali kunci yang dipakai ulang untuk permintaan lain tanpa menyimpan badannya. kode_status dan 
badan_respons boleh kosong, tetapi pada rancangan ini tidak pernah terlihat kosong oleh transaksi lain, sebab 
baris itu baru di-commit setelah keduanya terisi. Indeks unik pada pengguna_id dan kunci adalah bagian 
terpenting: dialah yang membuat dua permintaan kembar antre. 
app/Models/KunciIdempotensi.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Models; 
  
use Illuminate\Database\Eloquent\Model; 
  
final class KunciIdempotensi extends Model 
{ 
    public const UPDATED_AT = null; 
  
    protected $table = 'kunci_idempotensi'; 
  
    // Hanya ditulis middleware Idempoten, tidak pernah dari masukan klien. 
    protected $fillable = []; 
  
    protected function casts(): array 
    { 
        return ['kode_status' => 'integer']; 
    } 
} 
 
app/Exceptions/GalatIdempotensi.php 
<?php 
  
declare(strict_types=1); 

namespace App\Exceptions; 
  
final class GalatIdempotensi extends GalatDomain 
{ 
    public static function kunciTidakSah(): self 
    { 
        return new self( 
            'LP-11', 
            'Permintaan ini wajib membawa header Idempotency-Key: 16 sampai 64 huruf, angka, ' 
            .'garis bawah, atau tanda hubung.', 
            422, 
        ); 
    } 
  
    public static function dipakaiPermintaanLain(): self 
    { 
        return new self( 
            'LP-11', 
            'Idempotency-Key ini sudah dipakai untuk permintaan yang berbeda. Buat kunci baru.', 
            422, 
        ); 
    } 
  
    public static function sedangDiproses(): self 
    { 
        return new self( 
            'LP-11', 
            'Permintaan dengan Idempotency-Key ini sedang diproses. Ulangi sebentar lagi.', 
            409, 
        ); 
    } 
} 
 
2.2 Middleware. 
app/Http/Middleware/Idempoten.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Http\Middleware; 
  
use App\Exceptions\GalatIdempotensi; 
use App\Models\KunciIdempotensi; 
use Closure; 
use Illuminate\Database\UniqueConstraintViolationException; 
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\DB; 
use Symfony\Component\HttpFoundation\Response; 
use Throwable; 
  
/** 
 * Pemakaian pada rute:  ->middleware('idempoten')   Pasang SETELAH auth:sesi dan izin. 
 * 
 * LP-11: kunci yang sama menghasilkan respons yang sama dan efek satu kali. 
 * 
 * Caranya: catatan kunci dan seluruh pekerjaan controller berada dalam SATU transaksi. 
 *   - berhasil (2xx)  -> respons disimpan pada catatan, lalu keduanya di-commit bersama; 
 *   - gagal           -> keduanya dibatalkan bersama. Tidak ada jejak, kunci boleh dipakai lagi. 
 * Tidak ada keadaan "efeknya terjadi tetapi kuncinya tidak tercatat", maupun sebaliknya. 
 */ 
final class Idempoten 
{ 
    private const POLA_KUNCI = '/\A[A-Za-z0-9_-]{16,64}\z/'; 
  
    public function handle(Request $request, Closure $next): Response 
    { 
        $kunci = (string) $request->header('Idempotency-Key'); 
  
        if (preg_match(self::POLA_KUNCI, $kunci) !== 1) { 
            throw GalatIdempotensi::kunciTidakSah(); 
        } 
  
        $idPengguna = $request->user()->id; 
        $rute = $request->method().' '.$request->path(); 

        $sidik = hash('sha256', $rute."\n".$request->getContent()); 
  
        DB::beginTransaction(); 
  
        try { 
            $catatan = new KunciIdempotensi; 
            $catatan->forceFill([ 
                'pengguna_id' => $idPengguna, 
                'kunci' => $kunci, 
                'rute' => $rute, 
                'sidik' => $sidik, 
            ])->save(); 
        } catch (UniqueConstraintViolationException) { 
            // Kunci ini sudah tercatat. Bila pencatatnya masih berjalan, INSERT di atas MENUNGGU 
            // sampai ia selesai: indeks unik membuat permintaan kembar antre, bukan berlomba. 
            DB::rollBack(); 
  
            return $this->ulangi($idPengguna, $kunci, $sidik); 
        } 
  
        try { 
            $respons = $next($request); 
        } catch (Throwable $galat) { 
            DB::rollBack(); 
  
            throw $galat; 
        } 
  
        if (! $respons->isSuccessful()) { 
            DB::rollBack(); 
  
            return $respons; 
        } 
  
        $catatan->forceFill([ 
            'kode_status' => $respons->getStatusCode(), 
            'badan_respons' => $respons->getContent(), 
        ])->save(); 
  
        DB::commit(); 
  
        return $respons; 
    } 
  
    private function ulangi(int $idPengguna, string $kunci, string $sidik): Response 
    { 
        $catatan = KunciIdempotensi::query() 
            ->where('pengguna_id', $idPengguna) 
            ->where('kunci', $kunci) 
            ->first(); 
  
        if ($catatan === null || $catatan->kode_status === null) { 
            throw GalatIdempotensi::sedangDiproses(); 
        } 
  
        // Kunci sama tetapi isinya lain: bukan pengulangan, melainkan kunci yang dipakai ulang. 
        if ($catatan->sidik !== $sidik) { 
            throw GalatIdempotensi::dipakaiPermintaanLain(); 
        } 
  
        return response($catatan->badan_respons, $catatan->kode_status, [ 
            'Content-Type' => 'application/json', 
            'Idempotency-Replayed' => 'true', 
        ]); 
    } 
} 
 
Baca metode handle() sebagai lima keputusan. 
1. Kunci yang bentuknya salah ditolak sebelum apa pun. Polanya 16 sampai 64 karakter. UUID memenuhi syarat 
ini; teks pendek seperti bayar1 tidak, karena terlalu mudah bertabrakan dengan niat lain milik pengguna yang 
sama. 
2. Transaksi dibuka di sini, bukan di controller. DB::beginTransaction() dipanggil sebelum $next($request). 
Seluruh pekerjaan controller, termasuk DB::transaction() di dalam service yang dipanggilnya, menjadi 

transaksi bersarang di bawahnya. Laravel menerjemahkan transaksi bersarang menjadi savepoint, sehingga 
yang benar-benar menyimpan ke basis data hanyalah DB::commit() pada akhir middleware. 
3. Catatan ditulis lebih dulu, dengan INSERT biasa. Bila kunci itu sedang dipakai permintaan lain yang belum 
selesai, INSERT ini berhenti dan menunggu: MySQL tidak dapat memutuskan apakah indeks uniknya dilanggar 
sebelum transaksi lawannya selesai. Bila lawannya berhasil, INSERT ini gagal dengan pelanggaran indeks unik 
dan ditangani ulangi(). Bila lawannya batal, INSERT ini berhasil dan permintaan diproses seperti baru. 
4. Hanya jawaban 2xx yang disimpan. Jawaban gagal membatalkan seluruh transaksi, termasuk catatan 
kuncinya. Galat yang dilempar controller diperlakukan sama. 
5. Pengulangan dijawab dari catatan. ulangi() berjalan setelah rollBack(), di luar transaksi, sehingga 
membaca keadaan terkini. 
bootstrap/app.php — perubahan A1 
  
 use App\Exceptions\PenanganGalatApi; 
+use App\Http\Middleware\Idempoten; 
 use App\Http\Middleware\IdPermintaan; 
 use App\Http\Middleware\PaksaJson; 
   ... 
     ->withMiddleware(function (Middleware $middleware) { 
         $middleware->api(prepend: [PaksaJson::class, IdPermintaan::class, TanpaCache::class]); 
-        $middleware->alias(['sesi.segar' => SesiSegar::class, 'izin' => PeriksaIzin::class]); 
+        $middleware->alias([ 
+            'sesi.segar' => SesiSegar::class, 
+            'izin' => PeriksaIzin::class, 
+            'idempoten' => Idempoten::class, 
+        ]); 
  
         // Izin diperiksa SEBELUM kerangka mencari objek dari alamat. Tanpa baris ini, orang yang 
 
PERINGATAN — Yang disimpan adalah jawaban, bukan permintaan 
badan_respons memuat apa pun yang dikembalikan controller. Untuk checkout isinya nomor pesanan dan tagihan 
milik pengguna itu sendiri, dan catatannya hanya dapat dibaca oleh pengguna yang sama dengan kunci yang sama. 
Jangan memasang idempoten pada rute yang jawabannya memuat rahasia sekali tampil, misalnya POST /masuk: 
kunci sesi akan tersimpan sebagai teks biasa di tabel ini. 
 
2.3 Uji middleware. Uji ini tidak menunggu checkout. Ia mendaftarkan satu rute percobaan yang setiap 
pemanggilan berhasilnya membuat satu kategori, sehingga jumlah baris kategori sama dengan jumlah efek yang 
benar-benar terjadi. 
tests/Feature/Sistem/IdempotenTest.php 
<?php 
  
use App\Exceptions\GalatDomain; 
use App\Models\Kategori; 
use App\Models\KunciIdempotensi; 
use App\Models\Pengguna; 
use Illuminate\Http\Request; 
use Illuminate\Support\Facades\Route; 
use Illuminate\Support\Str; 
  
/* 
| Pintu uji: setiap pemanggilan yang berhasil membuat SATU kategori. Jumlah baris kategori 
| karenanya sama dengan jumlah efek yang benar-benar terjadi. 
*/ 
beforeEach(function () { 
    Route::middleware(['api', 'auth:sesi', 'idempoten']) 
        ->post('/api/_uji/catat', function (Request $permintaan) { 
            $kategori = Kategori::create([ 
                'nama' => $permintaan->input('nama'), 
                'slug' => Str::random(12), 
            ]); 
  
            if ($permintaan->boolean('gagal')) { 

                throw new GalatDomain('UJI', 'Gagal setelah menulis.', 409); 
            } 
            if ($permintaan->boolean('meledak')) { 
                throw new RuntimeException('galat tak terduga'); 
            } 
  
            return response()->json(['sukses' => true, 'data' => ['id' => $kategori->id]], 201); 
        }); 
  
    $this->andi = Pengguna::factory()->create(); 
    $this->kunci = 'kunci-uji-0123456789'; 
    $this->kirim = fn (array $badan, ?string $kunci = null) => $this->postJson( 
        '/api/_uji/catat', $badan, ['Idempotency-Key' => $kunci ?? $this->kunci], 
    ); 
}); 
  
it('menolak permintaan tanpa kunci atau dengan kunci yang bentuknya salah (LP-11)', function ( 
    ?string $kunci, 
) { 
    $header = $kunci === null ? [] : ['Idempotency-Key' => $kunci]; 
  
    $this->sebagai($this->andi) 
        ->postJson('/api/_uji/catat', ['nama' => 'Batik'], $header) 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'LP-11'); 
  
    expect(Kategori::count())->toBe(0); 
})->with([ 
    'tanpa header' => [null], 
    'terlalu pendek' => ['abc123'], 
    'terlalu panjang' => [str_repeat('a', 65)], 
    'berisi spasi' => ['kunci dengan spasi 123'], 
    'berisi baris baru' => ["kunci-uji-0123456789\n"], 
]); 
  
it('menjalankan permintaan pertama dan mencatat responsnya', function () { 
    $this->sebagai($this->andi); 
  
    ($this->kirim)(['nama' => 'Batik']) 
        ->assertCreated() 
        ->assertHeaderMissing('Idempotency-Replayed'); 
  
    $catatan = KunciIdempotensi::sole(); 
  
    expect($catatan->pengguna_id)->toBe($this->andi->id) 
        ->and($catatan->rute)->toBe('POST api/_uji/catat') 
        ->and($catatan->kode_status)->toBe(201) 
        // Yang disimpan adalah respons. Badan permintaan tidak pernah disimpan. 
        ->and($catatan->badan_respons)->not->toContain('Batik'); 
}); 
  
it('menjawab pengulangan dengan respons yang sama tanpa mengulang efeknya', function () { 
    $this->sebagai($this->andi); 
  
    $pertama = ($this->kirim)(['nama' => 'Batik'])->assertCreated(); 
    $kedua = ($this->kirim)(['nama' => 'Batik']) 
        ->assertCreated() 
        ->assertHeader('Idempotency-Replayed', 'true'); 
    ($this->kirim)(['nama' => 'Batik'])->assertCreated(); 
  
    expect($kedua->getContent())->toBe($pertama->getContent()) 
        ->and(Kategori::count())->toBe(1); 
}); 
  
it('menolak kunci yang sama untuk badan yang berbeda', function () { 
    $this->sebagai($this->andi); 
  
    ($this->kirim)(['nama' => 'Batik'])->assertCreated(); 
    ($this->kirim)(['nama' => 'Kriya']) 
        ->assertUnprocessable() 
        ->assertJsonPath('galat.kode', 'LP-11'); 
  
    expect(Kategori::pluck('nama')->all())->toBe(['Batik']); 
}); 
  
it('memisahkan kunci menurut pengguna', function () { 
    $this->sebagai($this->andi); 
    ($this->kirim)(['nama' => 'Batik'])->assertCreated(); 

    // Orang lain yang kebetulan memakai teks kunci yang sama tidak menerima respons milik Andi. 
    $this->sebagai(Pengguna::factory()->create()); 
    ($this->kirim)(['nama' => 'Kriya']) 
        ->assertCreated() 
        ->assertHeaderMissing('Idempotency-Replayed'); 
  
    expect(Kategori::count())->toBe(2) 
        ->and(KunciIdempotensi::count())->toBe(2); 
}); 
  
it('tidak meninggalkan efek maupun catatan bila permintaan gagal', function ( 
    string $pemicu, 
    int $status, 
) { 
    $this->sebagai($this->andi); 
  
    ($this->kirim)(['nama' => 'Batik', $pemicu => true])->assertStatus($status); 
  
    // Kategori sempat ditulis sebelum galat, lalu ikut dibatalkan bersama catatan kuncinya. 
    expect(Kategori::count())->toBe(0) 
        ->and(KunciIdempotensi::count())->toBe(0); 
})->with([ 
    'galat aturan bisnis' => ['gagal', 409], 
    'galat tak terduga' => ['meledak', 500], 
]); 
  
it('membolehkan kunci dipakai lagi setelah permintaan yang gagal', function () { 
    $this->sebagai($this->andi); 
  
    ($this->kirim)(['nama' => 'Batik', 'gagal' => true])->assertConflict(); 
    // Diulang: tetap gagal, tetap tanpa jejak. 
    ($this->kirim)(['nama' => 'Batik', 'gagal' => true])->assertConflict(); 
    expect(KunciIdempotensi::count())->toBe(0); 
  
    // Kunci yang SAMA, kali ini dengan badan yang sudah dibetulkan: dijalankan seperti baru. 
    ($this->kirim)(['nama' => 'Batik']) 
        ->assertCreated() 
        ->assertHeaderMissing('Idempotency-Replayed'); 
  
    expect(Kategori::count())->toBe(1); 
}); 
 
Uji "tidak meninggalkan efek maupun catatan bila permintaan gagal" adalah inti rancangan D.4: kategori sempat 
ditulis, galat terjadi sesudahnya, dan pada akhirnya tidak ada kategori maupun catatan kunci. Uji terakhir 
membuktikan akibat baiknya bagi pembeli: kunci yang sama dapat dipakai lagi setelah sebab kegagalannya 
dibetulkan. 
2.4 Alat uji balapan. Lima berkas berikut membentuk perintah lapak:balapan. Empat domain lain hanya perlu 
menulis satu kelas yang memenuhi antarmuka pertama. 
app/Balapan/SkenarioBalapan.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Balapan; 
  
/** 
 * Satu skenario uji balapan: beberapa proses PHP melakukan hal yang sama pada saat yang sama, 
 * lalu keadaan akhirnya diperiksa terhadap sebuah aturan yang tidak boleh dilanggar. 
 * 
 * Dijalankan dengan:  php artisan lapak:balapan <nama-skenario> 
 * Nama skenario adalah nama kelasnya dengan tanda hubung: RebutanStok -> rebutan-stok. 
 */ 
interface SkenarioBalapan 
{ 
    public function keterangan(): string; 
  
    /** Jumlah proses yang dilepas bersamaan. */ 
    public function proses(): int; 
  

    /** 
     * Menyiapkan data di basis data dan mengembalikan konteks untuk setiap proses anak. 
     * Dipanggil satu kali, di proses induk, sebelum balapan dimulai. 
     * 
     * @return array<string, mixed> harus dapat diubah menjadi JSON 
     */ 
    public function siapkan(): array; 
  
    /** 
     * Dipanggil di SETIAP proses anak, pada detik yang sama. 
     * 
     * @param  array<string, mixed>  $konteks 
     * @return string hasil singkat, misalnya "berhasil" atau "409 LP-4" 
     */ 
    public function jalankan(array $konteks, int $nomor): string; 
  
    /** 
     * Dipanggil satu kali di proses induk setelah semua anak selesai. 
     * 
     * @param  array<string, mixed>  $konteks 
     * @param  list<string>  $hasil  satu hasil dari setiap proses anak 
     * @return list<string> pelanggaran yang ditemukan; kosong berarti lulus 
     */ 
    public function periksa(array $konteks, array $hasil): array; 
} 
 
app/Console/Commands/Balapan.php — metode yang menjalankan satu skenario 
    private function jalankan(string $nama): bool 
    { 
        $skenario = self::muat($nama); 
        $jumlah = (int) ($this->option('proses') ?: $skenario->proses()); 
  
        $this->newLine(); 
        $this->line("<options=bold>{$nama}</>: {$skenario->keterangan()}"); 
  
        $konteks = $skenario->siapkan(); 
  
        // Setiap anak perlu waktu untuk menyalakan Laravel. Semuanya menunggu sampai detik ini, 
        // lalu berangkat bersama. Tanpa garis mulai, anak pertama sudah selesai sebelum 
        // anak terakhir lahir, dan tidak ada yang berlomba. 
        $konteks['mulai'] = microtime(true) + max(2.0, $jumlah * 0.3); 
        $muatan = base64_encode(json_encode($konteks, JSON_THROW_ON_ERROR)); 
  
        $anak = []; 
        for ($nomor = 1; $nomor <= $jumlah; $nomor++) { 
            $proses = new Process( 
                [PHP_BINARY, 'artisan', 'lapak:balapan-anak', $nama, (string) $nomor, $muatan], 
                base_path(), 
            ); 
            $proses->setTimeout(120); 
            $proses->start(); 
            $anak[] = $proses; 
        } 
  
        $hasil = []; 
        foreach ($anak as $proses) { 
            $proses->wait(); 
            $keluaran = trim($proses->getOutput()); 
            $hasil[] = $keluaran !== '' 
                ? $keluaran 
                : 'GALAT '.Str::limit(trim($proses->getErrorOutput()), 90); 
        } 
  
        $rekap = array_count_values($hasil); 
        ksort($rekap); 
        $this->table( 
            ['Hasil', 'Jumlah proses'], 
            array_map(null, array_keys($rekap), array_values($rekap)), 
        ); 
  
        $pelanggaran = $skenario->periksa($konteks, $hasil); 
  
        foreach ($pelanggaran as $pesan) { 
            $this->error('PELANGGARAN: '.$pesan); 
        } 
        if ($pelanggaran === []) { 

            $this->info("LULUS: {$jumlah} proses serentak, tidak ada aturan yang dilanggar."); 
        } 
  
        return $pelanggaran === []; 
    } 
 
app/Console/Commands/BalapanAnak.php 
<?php 
  
declare(strict_types=1); 
  
namespace App\Console\Commands; 
  
use Illuminate\Console\Command; 
use Illuminate\Database\QueryException; 
use Illuminate\Support\Facades\DB; 
use Illuminate\Support\Str; 
use Throwable; 
  
/** Satu peserta balapan. Dijalankan oleh lapak:balapan, tidak untuk diketik sendiri. */ 
final class BalapanAnak extends Command 
{ 
    protected $signature = 'lapak:balapan-anak {skenario} {nomor} {muatan}'; 
  
    protected $description = 'Proses anak uji balapan'; 
  
    protected $hidden = true; 
  
    public function handle(): int 
    { 
        if (app()->isProduction()) { 
            return self::FAILURE; 
        } 
  
        $skenario = Balapan::muat($this->argument('skenario')); 
        $muatan = base64_decode($this->argument('muatan')); 
        $konteks = json_decode($muatan, true, 512, JSON_THROW_ON_ERROR); 
  
        // Sambungan dibuka SEBELUM garis mulai, supaya yang berlomba adalah kuerinya, 
        // bukan lamanya membuka sambungan. 
        DB::connection()->getPdo(); 
  
        $tunggu = $konteks['mulai'] - microtime(true); 
        if ($tunggu > 0) { 
            usleep((int) ($tunggu * 1_000_000)); 
        } 
  
        try { 
            $hasil = $skenario->jalankan($konteks, (int) $this->argument('nomor')); 
        } catch (Throwable $galat) { 
            // Galat basis data sudah diawali SQLSTATE; galat lain diawali nama kelasnya. 
            $pesan = $galat instanceof QueryException 
                ? $galat->getMessage() 
                : class_basename($galat).': '.$galat->getMessage(); 
            $hasil = 'GALAT '.Str::limit($pesan, 64); 
        } 
  
        $this->line($hasil); 
  
        return self::SUCCESS; 
    } 
}