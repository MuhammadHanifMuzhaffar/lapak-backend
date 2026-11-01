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