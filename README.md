# Moduł do Prestashop 1.6.x do integracji z Automater.pl
###Instrukcja instalacji
1. Pobierz paczkę instalacyjną - [automater_presta1.6_latest.zip](http://automater.pl/files/plugins/automater_presta1.6_latest.zip)
2. Zaloguj się do panelu administracyjnego sklepu i przejdź do zakładki Moduły / Moduły i usługi
3. Kliknij w przycisk Dodaj nowy moduł w prawej górnej części strony i wybierz pobrany plik wtyczki
4. Korzystając z wyszukiwarki znajdź moduł o nazwie Automater.pl
5. Wybierz pozycje Automater.pl i zainstaluj go klikając w przycisk Instaluj
6. Zaloguj się do systemu Automater.pl i przejdź do zakładki Ustawienia / ustawienia / API
7. Jeśli klucze nie są wygenerowane kliknij w przycisk Wygeneruj nowe klucze
8. Przepisz wartości API Key i API Secret do konfiguracji wtyczki i uruchom ją ustawiając pozycje Status na Aktywny
9. W ustawieniach produktów w sklepie internetowym wyświetli się nowa sekcja Automater.pl - ID produktu w Automater, wystarczy wybrać istniejący produkt z listy (jeśli nie ma odpowiednika to należy go utworzyć w panelu Automater w zakładce Produkty / Sklep / lista produktów
10. Gotowe - od teraz produkty powiązane z Automater będą wysyłane automatycznie.

###Aktualizacja stanów magazynowych - CRON
Opcjonalnie można skonfigurować opcję synchronizacji stanu magazynowego w połączonych produktach z bazami kodów. Do uaktywnienia tej opcji potrzebny jest dostęp do CRON i dodanie do niego poniższego wpisu:
> */20 * * * * /usr/bin/curl --silent http://adres-sklepu-internetowego.pl/modules/automater/cron.php &>/dev/null

W powyższym poleceniu należy zamienić adres-sklepu-internetowego.pl na adres swojego sklepu. Dodanie tej komendy spowoduje, że co 20 minut stan magazynowy będzie aktualizaowany.
