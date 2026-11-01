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