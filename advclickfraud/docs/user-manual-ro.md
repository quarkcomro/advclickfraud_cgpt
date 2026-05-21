# Advanced Click Fraud Protection - Manual de utilizare

## Scop

Advanced Click Fraud Protection ajută comercianții PrestaShop să colecteze semnale defensive din browser, click-uri, cereri și amprente de rețea. Modulul este destinat colectării de dovezi, revizuirii riscului de fraudă și aplicării controlate a măsurilor împotriva scraping-ului sau fraudei prin click-uri publicitare.

Modulul este defensiv. Nu garantează detectarea tuturor cererilor frauduloase și nu trebuie să fie singurul control folosit pentru protecția traficului plătit.

## Compatibilitate

- PrestaShop 8.2 sau mai nou.
- Versiunile PHP suportate de instalarea PrestaShop țintă.
- Un utilizator de bază de date cu permisiuni pentru crearea tabelelor modulului la instalare.

## Prima instalare

1. Instalează modulul din Back Office PrestaShop.
2. Deschide pagina de configurare a modulului.
3. Păstrează modulul dezactivat până când setările sunt revizuite.
4. Păstrează modul de funcționare pe `Observe only` pentru prima perioadă de verificare în producție.
5. Salvează configurația pentru contextul magazinului curent.

Modulul pornește dezactivat și în mod de observare. Acest comportament este intenționat, pentru ca evenimentele de risc să poată fi analizate înainte ca traficul să fie limitat sau blocat.

## Setări principale

### Activare modul

Activează colectarea semnalelor și evaluarea riscului pe server pentru contextul magazinului curent.

### Mod de funcționare

- `Observe only`: înregistrează decizii și evenimente de risc fără să blocheze traficul.
- `Rate limit`: blochează doar când logica de risc atinge pragul de limitare.
- `Block high-risk traffic`: blochează traficul cu risc ridicat când pragul de blocare este atins.

Începe cu `Observe only`. Treci la moduri mai stricte doar după analiza traficului real și a posibilelor rezultate fals pozitive.

### Zile de păstrare a logurilor

Controlează câte zile sunt păstrate evenimentele detaliate de risc înainte de curățare.

### Interval de reîmprospătare pentru tabelul din administrare

Controlează numărătoarea pentru reîmprospătarea dashboard-ului din Back Office. Folosește `Disabled` când nu ai nevoie de reîmprospătare automată.

### Șterge datele de fraudă la dezinstalare

Când setarea este dezactivată, dezinstalarea modulului păstrează în baza de date evenimentele de fraudă și contoarele de limitare. Când setarea este activată, dezinstalarea modulului elimină permanent tabelele de date ale modulului.

Păstrează setarea dezactivată dacă datele pot fi necesare pentru audit, contestații sau reinstalare ulterioară. Activeaz-o doar când ștergerea permanentă este intenționată.

## Integrări de rețea

### Corelare JA4 și JA4H

Headerele JA4 și JA4H trebuie acceptate doar de la infrastructura de încredere, cum ar fi un CDN controlat, WAF, reverse proxy, HAProxy, NGINX sau edge worker.

Înainte de activarea corelării JA4:

1. Configurează adresele IP ale proxy-urilor de încredere.
2. Asigură-te că proxy-ul de margine elimină orice headere JA4 sau JA4H trimise de client.
3. Asigură-te că proxy-ul de margine setează el însuși headerele interne de amprentare.
4. Verifică evenimentele în modul de observare înainte de schimbarea comportamentului de aplicare.

Nu activa corelarea JA4 dacă headerele pot fi trimise direct de clienți publici.

## Protecție anti-scraping

Scorarea anti-scraping evaluează tipare de cereri pentru rute de produs, categorie și căutare. Pragul pentru pagini de produs și pragul pentru căutare controlează momentul în care viteza cererilor începe să crească riscul.

Recomandare de implementare:

1. Activează scorarea anti-scraping în mod de observare.
2. Analizează codurile de motiv și tiparele de trafic.
3. Ajustează pragurile în funcție de navigarea normală în catalog și comportamentul crawlerelor.
4. Activează limitarea sau blocarea doar după verificarea rezultatelor fals pozitive.

## Protecție împotriva fraudei prin click-uri publicitare

Scorarea fraudei prin click-uri publicitare înregistrează identificatori de click, indicii de atribuire și comportament observat după click. Poate ajuta la identificarea traficului plătit suspect, cum ar fi click-uri plătite fără interacțiune observată sau indicatori de automatizare în browser.

Folosește evenimentele înregistrate ca dovezi suport. Deciziile legate de platforme media plătite, excluderi de campanii și rambursări trebuie gestionate prin politicile și instrumentele de raportare ale platformei publicitare relevante.

## Verificări operaționale

- Confirmă că JavaScript-ul modulului este încărcat pe paginile magazinului când modulul este activ.
- Confirmă că endpoint-ul de colectare returnează răspunsuri JSON.
- Confirmă că endpoint-ul pixel este cerut doar când sunt prezenți identificatori de click publicitar.
- Analizează datele din Back Office înainte de activarea modurilor mai stricte.
- Retestează după modificări de temă, CDN, proxy, cache sau upgrade PrestaShop.

## Comportament la dezinstalare

Comportamentul la dezinstalare depinde de setarea `Delete stored fraud data on uninstall`. Intrările de configurare sunt eliminate la dezinstalare. Datele de fraudă stocate sunt șterse doar când această setare este activată înainte de dezinstalare.

## Recomandare pentru producție

Rulează teste funcționale într-o instanță PrestaShop 8.2 sau 9.x înainte de utilizarea în producție. Pentru magazine live, implementează inițial în modul de observare, analizează evenimentele colectate, apoi activează gradual controale mai stricte dacă datele susțin această decizie.
