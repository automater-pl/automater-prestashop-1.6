# Automater PHP SDK dla API v2
***Ważne***: najpierw zapoznaj się z [dokumentacją API v2](https://github.com/automater-pl/api-v2).

###Format odpowiedzi###
W przypadku pozytywnej odpowiedzi na żądanie GET (np. getProducts() lub getDatabases()) funkcje zwrócą tablicę w formacie:
```php
  [
    'code' => 200, // kod statusu
    'data' => [ // tablica z danymi
      0 => [
        'id' => 1,
        'name' => 'testowy produkt'
      ]
    ],
    'page' => 1, // strona wyników
    'current' => 1, // ilość wyświetlonych rekordów
    'all' => 5 // ilość wszystkich rekordów
  ]
```
W przypadku błędu system może zgłosić 3 rodzaje wyjątków:
- *AutomaterException*: dla błędów z API
- *NotFoundException*: dla nieodnalezionych zasobów 
- *TimeoutException*: dla przekroczonego czasu oczekiwania (domyślnie 10 sekund)

###Kody błędów###
- ***501***: nieprawidłowe uwierzytelnienie (błąd w API key lub API secret)
- ***551***: nieprawidłowy podpis wysłanego pakietu

##Przykłady użycia##
Pobranie listy produktów z systemu
```php
<?php
  // wczytanie klasy
  require_once('Automater-PHP-SDK/autoload.php');
  
  // utworzenie obiektu
  // jako argumenty należy przekazać kolejno: klucz API i API secret
  // wartości można pobrać z panelu wybierając zakładkę Ustawienia / Konto / API
  $automater = new \Automater\Automater("api key", "api secret");
  
  try {
    // argumenty: strona, ilość wyników
    $products = $automater->getProducts( 1, 50 );
  } catch(\Automater\Exception\AutomaterException $e) { 
    // błąd API, $e->getCode() zwróci błąd, $e->getMessage() opis błędu
    die($e->getCode().": ".$e->getMessage());
  } catch(\Automater\Exception\NotFoundException $e) { 
    // nie znaleziono zasobu, może wystąpić np. przy próbie pobrania szczegółów nieistniejącej bazy
    die("Not found: ".$e->getMessage());
  } catch(\Automater\Exception\TimeoutException $e) {
    // przekroczono limit czasu oczekiwania na odpowiedź
    die('Connection timed out');
  }
  
  // przykładowa odpowiedź
  Array
  (
    [code] => 200
    [data] => Array
      (
        [0] => Array
          (
            [id] => 38 // id produktu
            [database_id] => 480 // id bazy kodów
            [name] => testowy produktu // nazwa produktu
            [description] => <p>opis testowego produktu</p> // opis produktu ze znacznikami HTML
            [price] => 1 // cena za sztukę
            [currency] => PLN // waluta: PLN, USD, EUR lub GBP
            [available] => 7 // ilość dostępnych kodów / plików w połączonej bazie
          )
      )

    [page] => 1
    [current] => 1
    [count] => 16
  )

```
