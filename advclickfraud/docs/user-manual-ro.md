# Advanced Click Fraud Protection - Manual de utilizare

## Scop

Advanced Click Fraud Protection ajuta comerciantii PrestaShop sa colecteze semnale defensive din browser, click-uri, cereri si amprente de retea. Modulul este destinat colectarii de dovezi, revizuirii riscului de frauda si aplicarii controlate a masurilor impotriva scraping-ului sau fraudei prin click-uri publicitare.

Modulul este defensiv. Nu garanteaza detectarea tuturor cererilor frauduloase si nu trebuie sa fie singurul control folosit pentru protectia traficului platit.

## Compatibilitate

- PrestaShop 8.2 sau mai nou.
- Versiunile PHP suportate de instalarea PrestaShop tinta.
- Un utilizator de baza de date cu permisiuni pentru crearea tabelelor modulului la instalare.

## Prima instalare

1. Instaleaza modulul din Back Office PrestaShop.
2. Deschide pagina de configurare a modulului.
3. Pastreaza modulul dezactivat pana cand setarile sunt revizuite.
4. Pastreaza modul de functionare pe `Observe only` pentru prima perioada de verificare in productie.
5. Salveaza configuratia pentru contextul magazinului curent.

Modulul porneste dezactivat si in mod de observare. Acest comportament este intentionat, pentru ca evenimentele de risc sa poata fi analizate inainte ca traficul sa fie limitat sau blocat.

## Setari principale

### Activare modul

Activeaza colectarea semnalelor si evaluarea riscului pe server pentru contextul magazinului curent.

### Mod de functionare

- `Observe only`: inregistreaza decizii si evenimente de risc fara sa blocheze traficul.
- `Rate limit`: blocheaza doar cand logica de risc atinge pragul de limitare.
- `Block high-risk traffic`: blocheaza traficul cu risc ridicat cand pragul de blocare este atins.

Incepe cu `Observe only`. Treci la moduri mai stricte doar dupa analiza traficului real si a posibilelor rezultate fals pozitive.

### Zile de pastrare a logurilor

Controleaza cate zile sunt pastrate evenimentele detaliate de risc inainte de curatare.

### Interval de reimprospatare pentru tabelul din administrare

Controleaza numaratoarea pentru reimprospatarea dashboard-ului din Back Office. Foloseste `Disabled` cand nu ai nevoie de reimprospatare automata.

### Sterge datele de frauda la dezinstalare

Cand setarea este dezactivata, dezinstalarea modulului pastreaza in baza de date evenimentele de frauda si contoarele de limitare. Cand setarea este activata, dezinstalarea modulului elimina permanent tabelele de date ale modulului.

Pastreaza setarea dezactivata daca datele pot fi necesare pentru audit, contestatii sau reinstalare ulterioara. Activeaz-o doar cand stergerea permanenta este intentionata.

## Integrari de retea

### Corelare JA4 si JA4H

Headerele JA4 si JA4H trebuie acceptate doar de la infrastructura de incredere, cum ar fi un CDN controlat, WAF, reverse proxy, HAProxy, NGINX sau edge worker.

Inainte de activarea corelarii JA4:

1. Configureaza adresele IP ale proxy-urilor de incredere.
2. Asigura-te ca proxy-ul de margine elimina orice headere JA4 sau JA4H trimise de client.
3. Asigura-te ca proxy-ul de margine seteaza el insusi headerele interne de amprentare.
4. Verifica evenimentele in modul de observare inainte de schimbarea comportamentului de aplicare.

Nu activa corelarea JA4 daca headerele pot fi trimise direct de clienti publici.

## Protectie anti-scraping

Scorarea anti-scraping evalueaza tipare de cereri pentru rute de produs, categorie si cautare. Pragul pentru pagini de produs si pragul pentru cautare controleaza momentul in care viteza cererilor incepe sa creasca riscul.

Recomandare de implementare:

1. Activeaza scorarea anti-scraping in mod de observare.
2. Analizeaza codurile de motiv si tiparele de trafic.
3. Ajusteaza pragurile in functie de navigarea normala in catalog si comportamentul crawlerelor.
4. Activeaza limitarea sau blocarea doar dupa verificarea rezultatelor fals pozitive.

## Protectie impotriva fraudei prin click-uri publicitare

Scorarea fraudei prin click-uri publicitare inregistreaza identificatori de click, indicii de atribuire si comportament observat dupa click. Poate ajuta la identificarea traficului platit suspect, cum ar fi click-uri platite fara interactiune observata sau indicatori de automatizare in browser.

Foloseste evenimentele inregistrate ca dovezi suport. Deciziile legate de platforme media platite, excluderi de campanii si rambursari trebuie gestionate prin politicile si instrumentele de raportare ale platformei publicitare relevante.

## Verificari operationale

- Confirma ca JavaScript-ul modulului este incarcat pe paginile magazinului cand modulul este activ.
- Confirma ca endpoint-ul de colectare returneaza raspunsuri JSON.
- Confirma ca endpoint-ul pixel este cerut doar cand sunt prezenti identificatori de click publicitar.
- Analizeaza datele din Back Office inainte de activarea modurilor mai stricte.
- Retesteaza dupa modificari de tema, CDN, proxy, cache sau upgrade PrestaShop.
