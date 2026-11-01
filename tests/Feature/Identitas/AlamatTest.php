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