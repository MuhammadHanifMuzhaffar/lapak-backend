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
saya/produk/{id}