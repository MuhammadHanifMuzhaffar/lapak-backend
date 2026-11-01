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