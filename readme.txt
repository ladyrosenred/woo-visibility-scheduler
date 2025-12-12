=== WooCommerce Product Visibility Scheduler ===
Tags: woocommerce, products, scheduler, visibility, automation
Requires at least: 5.0
Tested up to: 6.4.2
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ütemezett termék láthatóság és státusz változtatás WooCommerce-hez.

== Description ==

A WooCommerce Product Visibility Scheduler bővítmény lehetővé teszi a termékek láthatóságának és státuszának automatikus változtatását egy megadott időpontban.

Főbb funkciók:

* Privát termékek automatikus publikussá tétele
* Draft termékek automatikus publikálása
* Időzóna-kezelés
* Egyszerű felhasználói felület a WooCommerce termék szerkesztőben
* Rugalmas ütemezés kezelés

== Installation ==

1. Töltsd fel a 'woo-visibility-scheduler' mappát a `/wp-content/plugins/` könyvtárba
2. Aktiváld a bővítményt a WordPress admin felületén a 'Bővítmények' menüben
3. A WooCommerce termék szerkesztőben megjelenik egy új meta box az ütemezés beállításához

== Frequently Asked Questions ==

= Hogyan állíthatok be ütemezett láthatóság változtatást? =

1. Nyisd meg a terméket szerkesztésre
2. A jobb oldali "Láthatóság Ütemezés" dobozban állítsd be a kívánt időpontot
3. Mentsd el a terméket

= Milyen státuszú termékeknél használható az ütemezés? =

Az ütemezés a következő esetekben használható:
* Privát -> Publikus láthatóság változtatás
* Draft -> Publikus státusz változtatás

== Screenshots ==

1. Ütemezés beállítása a termék szerkesztő oldalon
2. Ütemezések listája és kezelése

== Changelog ==

= 1.0.1 =
* Admin ütemezés lista: időpont megjelenítés javítva (UTC -> kiválasztott időzóna), és Időzóna oszlop hozzáadva
* Termékenkénti időzóna mentése (`_visibility_scheduler_timezone`) és használata (fallback: plugin beállítás, majd WP webhely időzóna)
* Metabox: dátum + idő inputok, helyes időzóna-konverzió megjelenítéskor és mentéskor
* Időzóna validálása mentés előtt (invalid értékek nem kerülnek mentésre)
* Kézi futtatás ("azonnali futtatás") egyértelmű visszajelzése: esedékes tételek száma / következő esedékes időpont
* Uninstall: a delete_data beállítás tiszteletben tartása, új meta kulcs törlése delete_data=yes esetén
* Admin UI: Tailwind CDN eltávolítva, lokális CSS betöltés
* Cron ütemezés finomítva (csak akkor schedule, ha hiányzik)
* Törlés hardening az admin listában (capability + sanitization)

= 1.0 =
* Első kiadás
* Alapvető ütemezési funkciók
* Időzóna támogatás
* Admin felület

== Upgrade Notice ==

= 1.0.1 =
Javítások: időzóna-kezelés, admin lista megjelenítés, kézi futtatás visszajelzés, uninstall viselkedés, cron finomítás.

= 1.0 =
Első stabil verzió

== Privacy Policy ==

A bővítmény nem gyűjt személyes adatokat és nem küld adatokat külső szervereknek.
