# BDO Craft — Yol Haritası

> Son güncelleme: 2026-10-09 · Boyut: **S** küçük (1 oturum) · **M** orta (birkaç oturum) · **L** büyük

## Hedef

Black Desert Online life skill'leri (cooking, alchemy, processing) için üretim planlayıcı.
Kullanıcı bir eşya arar ve kaç tane üreteceğini girer. Site tüm alt tarif ağacını açar, alışveriş
listesini, üretim adımlarını, toplam maliyeti ve kârı gösterir.

**Profesyonel olması şu demek:** doğru ve güncel veri, hızlı ve mobil uyumlu arayüz, kendi
alan adında 7/24 çalışan bir site, bozulmayan kod (test + CI), kaybolmayan veri (yedek).

## Şu anki durum

| Alan | Durum |
| --- | --- |
| API + hesaplayıcı (`craft.php`) | ✅ Çalışıyor, test edildi (bkz. `api/README.md`) |
| Veritabanı şeması, import, fiyat güncelleyici | ✅ Repoda, tekrarlanabilir |
| Cooking liste sayfası | ✅ Çalışıyor (filtreler dahil) |
| Hesaplayıcı arayüzü | ❌ Yok, asıl ürün bu |
| İkonlar | ❌ 245 ikonun 206'sı yüklenmiyor (bdocodex hotlink engeli) |
| Alchemy / Processing / Items / tarif detay sayfaları | ❌ Boş dosyalar, `recipe.php` linkleri 404 |
| Veri kaynağı | ✅ Yeni scraper (`import/scrape.php`); veri 2026-10-09 itibarıyla güncel. Eşya detayları (açıklama, NPC satıcıları) hâlâ eski. |
| Fiyatlar | ⚠️ Sadece EU; arsha.io sık isteği engelliyor, otomatik güncelleme yok |
| Git | ✅ Tek repo (`api/` + `site/`), iki eski reponun geçmişi korunarak birleştirildi. GitHub: `kerimozgurtukenmez/bdo-api`. |
| Test | ✅ 29 test (PHPUnit) + PHPStan seviye 6, `composer check` |
| CI, yayın, yedek | ❌ Yok |

---

## Önce verilmesi gereken kararlar

Bunlar ilerideki işin şeklini değiştiriyor; ilgili fazdan önce netleşmeli.

| # | Karar | Önerim | Gerekçe | Ne zaman |
| --- | --- | --- | --- | --- |
| K1 | ~~Veri nereden geliyor?~~ | ✅ **Karar verildi:** bdocodex, site sahibinin izniyle. Eski scraper kayıptı, yenisi yazıldı. | Eski scrape doğruymuş: değişmeyen kayıtlar birebir aynı çıktı, sadece iki alan yanlış adlandırılmıştı. | — |
| K2 | ~~Tek repo mu, iki repo mu?~~ | ✅ **Tek repo:** `api/` (uç noktalar `api/public/`), `site/` | API ile site birlikte değişiyor; iki eski reponun geçmişi korundu. Repo: GitHub'daki `bdo-api`; eski `bdo-site` reposu kaldırılacak. | — |
| K3 | Frontend teknolojisi | **İçerik sayfaları PHP'de** (SEO için sunucuda render), **hesaplayıcı Vue** ile | Hesaplayıcı çok etkileşimli: ağaç, tarif değiştirme, ikame, canlı toplamlar. Vanilla JS ile büyüdükçe yönetmesi zorlaşır. Vue öğrenmesi en kolay seçenek. | Faz 1 öncesi |
| K4 | Barındırma | **Küçük VPS + Docker** (aylık ~5 €) | Fiyat güncellemesi için cron ve uzun çalışan betik lazım. Paylaşımlı hosting bunları kısıtlıyor. Docker ile yerel ortam ve sunucu aynı olur. | Faz 5 öncesi |
| K5 | Site adı ve domain | Sen seç | Şu an her yerde "MyWebsite" yazıyor. | Faz 5 öncesi |
| K6 | ~~Arayüz dili~~, bölgeler | ✅ **Arayüz İngilizce.** Bölge seçici Faz 3'te. | bdocodex Türkçe dahil birçok dil sunuyor; çok dilli arayüz ileride eklenebilir. | Faz 3 |

---

## Faz 0 — Temel düzen · S

Amaç: güvenli bir başlangıç noktası. Hiçbir şey kaybolmasın, sonraki işler sağlam zemine otursun.

- [x] Mevcut değişiklikleri commit et ve GitHub'a gönder
- [x] K2: tek repoya geç, bu dosyayı repoya taşı
- [x] K1: veri kaynağını netleştir, yeni scraper yaz, veriyi tazele
- [ ] Kod kuralları: yorumlar İngilizce, tek biçim (PHP-CS-Fixer, Prettier)
- [x] Hesaplayıcı için ilk testler (PHPUnit): 29 test; ilk çalıştırmada gerçek bir hata yakaladı

## Faz 1 — MVP: Hesaplayıcı arayüzü · L

Amaç: asıl ürün. **Bittiğinde:** herhangi bir eşya aranıp tam üretim planı görülebilir.

- [ ] **İkonları kendi sunucumuzdan sun:** bir kerelik indirici, yerel kopya, yüklenemezse yedek görsel
- [ ] Arama kutusu: otomatik tamamlama, life skill etiketleri (cooking / alchemy / processing)
- [ ] Adet girişi, mod (hepsini üret / en ucuzu), verim varsayımı (min / ortalama / max)
- [ ] Sonuç ekranı:
  - [ ] Alışveriş listesi: adet, birim fiyat, toplam, fiyatın kaynağı (pazar / NPC)
  - [ ] Üretim adımları sırayla, life skill'e göre gruplu
  - [ ] Açılır-kapanır tarif ağacı
  - [ ] Toplam maliyet, pazar değeri, kâr (pazar vergisi ve Value Pack seçeneğiyle)
- [ ] Ağaç üzerinde: tarifi değiştir, "satın al" olarak işaretle, ikame malzeme seç
- [ ] Paylaşılabilir link: tüm seçimler URL'de durur, kopyalanınca aynı plan açılır
- [ ] Mobil uyumlu tasarım
- [ ] Tooltip düzeltmesi: yanıltıcı `buy_price` yerine gerçek fiyat

## Faz 2 — Gezinme sayfaları · M

Amaç: hesaplayıcı dışında da gezilebilir, Google'da bulunabilir bir site.

- [ ] Tek tarif listesi şablonu: cooking, alchemy ve processing aynı kodu kullanır (cooking.php'yi üç kez kopyalamak yerine)
- [ ] Tarif detay sayfası (`recipe.php`): malzeme slotları, ikameler, ürünler, "bunu hesapla" butonu
- [ ] Eşya sayfası: fiyat, hangi tariflerle yapılır, hangi tariflerde kullanılır
- [ ] Navbar'daki global arama
- [ ] Temiz URL'ler (`/item/9003-white-sauce`)
- [ ] Ana sayfa: büyük arama kutusu ve öne çıkanlar

## Faz 3 — Veri ve fiyatlar · M

Amaç: verinin güncel, doğru ve kendiliğinden yenilenir olması.

- [x] Tekrarlanabilir veri güncellemesi: `scrape.php` → JSON → `import.php`, "neler değişti" raporuyla
- [ ] Eşya detaylarını scrape et: açıklama, ağırlık ve bdocodex'in `sellspecialitems` sorgusuyla gerçek NPC satıcıları (şu an satıcılar açıklama metninden tahmin ediliyor). Eşya başına istek gerektiği için önbellekli ve yavaş çalışmalı.
- [ ] Veri doğrulama testleri (bugün elle yaptığımız denetimin otomatik hali)
- [ ] Elle varsayılan tarif tablosu: otomatik seçimin yanlış olduğu eşyalar için (ör. Black Stone'un Black Gem öğütmeden yapılması)
- [ ] Fiyat güncellemesini zamanla (cron, 30–60 dk); arayüzde "fiyatlar X dk önce güncellendi"
- [ ] Çoklu bölge: `item_prices` tablosuna bölge sütunu ve bölge seçici (K6)
- [ ] Fiyat geçmişi tablosu. Erken başlamak önemli: grafikler için verinin birikmesi gerekiyor.

## Faz 4 — Mühendislik kalitesi · M

Amaç: kod büyüdükçe bozulmasın, değişiklik yapmak korkutucu olmasın.

- [x] Composer (geliştirme araçları için; API'nin çalışması için gerekmiyor)
- [x] Testler: hesaplayıcı (ağaç, döngüler, toplamlar, modlar), import kuralları, bdocodex ayrıştırma, fiyat seçimi
- [ ] Testleri genişlet: API uç noktaları (yanıt formatı, hata kodları)
- [x] Statik analiz: PHPStan seviye 6, hatasız
- [ ] PHPStan seviyesini yükselt (8'de 12 küçük bulgu var); ESLint (JS)
- [ ] CI: GitHub Actions, her push'ta lint + test
- [ ] Sürümlü şema migration'ları (`--fresh` ile her şeyi silmek yerine)
- [ ] API sürümleme (`/api/v1/...`) ve hız sınırı (rate limit)
- [ ] Önbellek: HTTP cache başlıkları, sık kullanılan hesaplamalar için APCu

## Faz 5 — Yayına alma · M

Amaç: kendi domaininde, güvenli ve izlenen bir site.

- [ ] İsim, logo, favicon, domain (K5)
- [ ] Sunucu kurulumu (K4), Docker ile yerel ortamın aynısı
- [ ] Güvenlik: HTTPS, root yerine şifreli ayrı veritabanı kullanıcısı, sırlar `config.local.php`'de
- [ ] Otomatik deploy (GitHub Actions)
- [ ] **Günlük otomatik veritabanı yedeği ve geri yükleme denemesi.** Bu projeyi bir kez yedekten kurtarmak zorunda kaldık.
- [ ] Hata kaydı ve uptime izleme (site çökerse haber gelsin)
- [ ] Yasal: "Pearl Abyss ile bağlantılı değildir" notu, veri kaynaklarına atıf (bdocodex, arsha.io), gizlilik politikası
- [ ] SEO: meta/OG etiketleri, `sitemap.xml`, `robots.txt`
- [ ] Gizlilik dostu ziyaretçi analitiği (Plausible veya Umami)

## Faz 6 — Büyüme özellikleri · sürekli

Yayından sonra, önerdiğim öncelik sırasıyla:

1. **Kârlılık sıralaması:** "Şu an en kârlı tarifler", life skill ve seviyeye göre filtreli
2. **Çoklu hedef:** birden fazla eşyayı tek alışveriş listesinde birleştir (ör. 10 Beer + 20 Meat Stew). Hesaplayıcının yapısı buna hazır.
3. **Ustalık (mastery) etkisi:** gerçek verim ve rare proc oranları. Önce araştırma gerekiyor.
4. **Imperial teslimat hesaplayıcı:** veride 812 Imperial Cuisine ve 290 Imperial Alchemy tarifi zaten var
5. Fiyat geçmişi grafikleri
6. Kayıtlı listeler ve favoriler: önce tarayıcıda, hesap sistemi gerekmeden
7. Türkçe arayüz

---

## Çalışma şekli

- Her oturumda bir fazdan bir veya birkaç madde: küçük, test edilmiş, commit edilmiş parçalar.
- Biten maddeler bu dosyada işaretlenir; böylece her oturum kaldığı yerden başlar.
- Her faz sonunda çalışan bir sürüm olur. Yarım kalan iş ana dalı bozmaz.
