Ниже — один цельный Markdown-файл. Его можно полностью скопировать и сохранить как:

```
PLAN.md
```

Markdown

````
# План реализации `php-mini-database`

Подробный пошаговый план создания небольшой учебной реляционной базы данных на PHP.

Проект должен быть не попыткой написать MySQL, а понятной лабораторией для изучения:

- хранения данных на диске;
- форматов файлов;
- записей и страниц;
- индексов;
- SQL;
- выполнения запросов;
- транзакций;
- WAL;
- восстановления после падения;
- блокировок;
- конкурентного доступа;
- производительности;
- взаимодействия базы с другими системными компонентами.

---

# 1. Цель проекта

Создать небольшую учебную базу данных на PHP, которую можно использовать из:

- CLI;
- PHP-приложения;
- HTTP-сервера;
- `php-systems-platform`;
- тестовых и benchmark-сценариев.

База должна поддерживать:

- таблицы;
- записи;
- первичный ключ;
- вставку;
- чтение;
- обновление;
- удаление;
- простые условия `WHERE`;
- сортировку;
- ограничение количества строк;
- индексы;
- хранение на диске;
- восстановление после перезапуска;
- простые транзакции;
- простой клиентский API;
- ограниченный SQL;
- измерения производительности.

---

# 2. Главный принцип проекта

Проект развивается от простого к сложному:

```text
In-memory table
    ↓
File-backed storage
    ↓
Records and pages
    ↓
Primary index
    ↓
Table API
    ↓
Query executor
    ↓
SQL parser
    ↓
Transactions
    ↓
WAL
    ↓
Recovery
    ↓
Concurrency
    ↓
Network protocol
````

Не нужно начинать с SQL, транзакций и сетевого протокола.

Сначала нужно сделать надёжное и понятное хранение записей.

# 3. Что не нужно реализовывать

На первом этапе не нужно делать:

* полную совместимость с MySQL;

* полноценный SQL standard;

* сложный query optimizer;

* distributed database;

* replication;

* sharding;

* MVCC;

* Raft;

* сложный buffer pool;

* полноценную систему прав;

* сложные типы данных;

* stored procedures;

* triggers;

* foreign keys;

* сложные JOIN;

* production-grade durability;

* бинарный MySQL protocol.

Проект должен оставаться маленьким и учебным.

# 4. Предлагаемое название

```
php-mini-database
```

Короткое описание:

```
A small educational relational database written in PHP.
Explore storage engines, records, indexes, queries,
transactions, WAL, recovery, and database internals.
```

# 5. Основные этапы

```
Phase 0   — Подготовка проекта
Phase 1   — In-memory database
Phase 2   — Table and row model
Phase 3   — File format
Phase 4   — Persistent storage
Phase 5   — Primary key and indexes
Phase 6   — Update and delete
Phase 7   — Query API
Phase 8   — SQL lexer
Phase 9   — SQL parser
Phase 10  — Query executor
Phase 11  — Pages and buffer management
Phase 12  — Transactions
Phase 13  — WAL
Phase 14  — Crash recovery
Phase 15  — Concurrency and locking
Phase 16  — Network protocol
Phase 17  — Benchmarks
Phase 18  — Documentation
Phase 19  — Integration with php-systems-platform
```

# 6. Phase 0 — Подготовка проекта

## Шаг 0.1 — Создать репозиторий

Создать репозиторий:

```
Researcher86/php-mini-database
```

## Шаг 0.2 — Создать базовую структуру

Предлагаемая структура:

```
php-mini-database/
├── bin/
│   └── database
├── config/
├── docs/
├── examples/
├── src/
│   ├── Database.php
│   ├── Table.php
│   ├── Row.php
│   ├── Schema/
│   ├── Storage/
│   ├── Index/
│   ├── Query/
│   ├── Sql/
│   ├── Transaction/
│   └── Recovery/
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Stress/
├── benchmarks/
├── var/
│   └── data/
├── composer.json
├── phpunit.xml
├── README.md
└── LICENSE
```

## Шаг 0.3 — Настроить Composer

Минимальные зависимости:

* PHP;

* PHPUnit;

* PHPStan или Psalm;

* PHP CS Fixer;

* Symfony Console — по желанию.

Не добавлять тяжёлые зависимости без необходимости.

## Шаг 0.4 — Определить минимальную версию PHP

Рекомендуемый вариант:

```
PHP 8.4+
```

Если проект должен использовать современные возможности PHP:

```
PHP 8.5+
```

## Шаг 0.5 — Настроить проверки

Добавить команды:

JSON

```
{
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "format": "php-cs-fixer fix",
        "check": [
            "@test",
            "@analyse"
        ]
    }
}
```

## Результат этапа

Должен существовать запускаемый проект с:

* Composer;

* тестами;

* статическим анализом;

* пустым, но рабочим `Database`;

* базовым README.

# 7. Phase 1 — In-memory database

На первом этапе база работает только в памяти.

Цель — сначала понять модель данных без файлов, сериализации и восстановления.

## Шаг 1.1 — Создать класс `Database`

Пример API:

PHP

```
$database = new Database();

$users = $database->createTable('users');
```

Обязанности `Database`:

* хранить список таблиц;

* создавать таблицу;

* получать таблицу;

* удалять таблицу;

* проверять существование таблицы.

## Шаг 1.2 — Создать класс `Table`

Пример:

PHP

```
$users = $database->createTable('users');

$users->insert([
    'id' => 1,
    'name' => 'Tanat',
]);
```

`Table` должен отвечать за:

* строки;

* схему;

* вставку;

* чтение;

* обновление;

* удаление;

* поиск.

## Шаг 1.3 — Создать модель строки

Сначала можно использовать массив:

PHP

```
[
    'id' => 1,
    'name' => 'Tanat',
    'age' => 40,
]
```

Но желательно выделить понятие строки:

PHP

```
final class Row
{
    public function __construct(
        public readonly array $values,
    ) {
    }
}
```

На этом этапе не нужно делать сложный объект.

## Шаг 1.4 — Реализовать вставку

PHP

```
$users->insert([
    'id' => 1,
    'name' => 'Tanat',
]);
```

Проверить:

* запись добавляется;

* отсутствующие поля обрабатываются;

* неизвестные поля отклоняются;

* повторная вставка пока либо разрешается, либо запрещается явно.

## Шаг 1.5 — Реализовать чтение

PHP

```
$users->all();

$users->find(1);
```

Поддержать:

* получение всех строк;

* поиск по ID;

* возврат `null`, если запись не найдена.

## Шаг 1.6 — Реализовать удаление

PHP

```
$users->delete(1);
```

Проверить:

* удаление существующей записи;

* удаление отсутствующей записи;

* повторное удаление;

* количество оставшихся строк.

## Шаг 1.7 — Реализовать обновление

PHP

```
$users->update(1, [
    'name' => 'Updated',
]);
```

Проверить:

* обновление существующей строки;

* обновление отсутствующей строки;

* изменение одного поля;

* сохранение остальных полей.

## Шаг 1.8 — Добавить простую схему

Пример:

PHP

```
$users = $database->createTable('users', [
    'id' => 'int',
    'name' => 'string',
    'age' => 'int',
]);
```

Поддержать типы:

* `int`;

* `string`;

* `bool`;

* `float`;

* `null`.

Проверять типы при вставке и обновлении.

## Результат Phase 1

Должен работать следующий код:

PHP

```
$database = new Database();

$users = $database->createTable('users', [
    'id' => 'int',
    'name' => 'string',
    'age' => 'int',
]);

$users->insert([
    'id' => 1,
    'name' => 'Tanat',
    'age' => 40,
]);

$users->insert([
    'id' => 2,
    'name' => 'Alex',
    'age' => 35,
]);

$user = $users->find(1);

$users->update(1, [
    'age' => 41,
]);

$users->delete(2);
```

# 8. Phase 2 — Table and schema model

На этом этапе нужно отделить структуру таблицы от её данных.

## Шаг 2.1 — Создать `ColumnDefinition`

PHP

```
final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable = false,
        public readonly mixed $default = null,
    ) {
    }
}
```

## Шаг 2.2 — Создать `TableSchema`

PHP

```
final class TableSchema
{
    /**
     * @param list<ColumnDefinition> $columns
     */
    public function __construct(
        public readonly string $tableName,
        public readonly array $columns,
    ) {
    }
}
```

## Шаг 2.3 — Поддержать обязательные поля

Пример:

PHP

```
$users = $database->createTable('users', [
    'id' => [
        'type' => 'int',
        'nullable' => false,
    ],
    'name' => [
        'type' => 'string',
        'nullable' => false,
    ],
]);
```

Проверять:

* обязательное поле присутствует;

* nullable-поле может быть `null`;

* default применяется, если значение не передано.

## Шаг 2.4 — Добавить первичный ключ в схему

PHP

```
$users = $database->createTable(
    'users',
    schema: [
        'id' => 'int',
        'name' => 'string',
    ],
    primaryKey: 'id',
);
```

Проверять:

* первичный ключ существует;

* значение ключа уникально;

* ключ не может быть `null`;

* ключ нельзя изменить без отдельной операции.

## Результат Phase 2

Появляется формальная модель:

```
Database
  └── Table
        ├── TableSchema
        │     ├── Columns
        │     └── PrimaryKey
        └── Rows
```

# 9. Phase 3 — Формат хранения записей

Теперь нужно перейти от массивов PHP к собственному формату данных.

Главная цель — понять, как запись превращается в байты.

## Шаг 3.1 — Определить формат записи

Для первой версии можно использовать length-prefixed binary format.

Пример:

```
[record length]
[record id]
[column count]
[column 1 length]
[column 1 value]
[column 2 length]
[column 2 value]
...
```

Не нужно сразу делать сложный page format.

## Шаг 3.2 — Создать `RecordEncoder`

PHP

```
interface RecordEncoder
{
    public function encode(Row $row): string;

    public function decode(string $data): Row;
}
```

## Шаг 3.3 — Определить кодирование типов

Пример:

```
int    → 8 bytes
float  → 8 bytes
bool   → 1 byte
string → length + bytes
null   → type marker
```

Для каждой колонки нужно хранить type marker.

Пример:

```
0x01 = int
0x02 = string
0x03 = bool
0x04 = float
0x05 = null
```

## Шаг 3.4 — Сделать round-trip тесты

Проверить:

```
Row → encode → decode → Row
```

Тестировать:

* пустую строку;

* строки с Unicode;

* большие строки;

* `null`;

* отрицательные числа;

* большие числа;

* `float`;

* `bool`;

* разные комбинации полей.

## Шаг 3.5 — Добавить checksum

Для обнаружения повреждения записи можно использовать:

* CRC32;

* SHA-256;

* другой простой checksum.

Для учебного проекта достаточно CRC32.

Формат:

```
[record length]
[payload]
[checksum]
```

Проверять checksum при чтении.

## Результат Phase 3

Можно преобразовать строку в байты и обратно:

PHP

```
$encoded = $encoder->encode($row);

$decoded = $encoder->decode($encoded);
```

# 10. Phase 4 — Persistent storage

Теперь данные должны сохраняться на диске.

## Шаг 4.1 — Создать `DataFile`

PHP

```
final class DataFile
{
    public function append(string $data): int
    {
        // Возвращает offset записи.
    }

    public function read(int $offset, int $length): string
    {
    }
}
```

## Шаг 4.2 — Использовать append-only storage

Каждая новая запись добавляется в конец файла:

````
record 1
record 2
record 3
record 4
```

Не нужно сразу перезаписывать старые записи.

Преимущества:

- простая реализация;
- понятная модель;
- последовательная запись;
- удобная демонстрация recovery;
- легко отслеживать offsets.

---

## Шаг 4.3 — Определить формат файла

Пример:

~~~text
[database file header]

[record header]
[record payload]

[record header]
[record payload]

[record header]
[record payload]
````

Header может содержать:

* magic bytes;

* version;

* page size;

* table identifier;

* checksum;

* metadata offset.

## Шаг 4.4 — Создать `RecordPointer`

PHP

```
final class RecordPointer
{
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
    ) {
    }
}
```

## Шаг 4.5 — Хранить offsets вместо строк в памяти

Вместо:

PHP

```
[
    1 => $row1,
    2 => $row2,
]
```

использовать:

PHP

```
[
    1 => new RecordPointer(offset: 100, length: 84),
    2 => new RecordPointer(offset: 184, length: 91),
]
```

## Шаг 4.6 — Реализовать загрузку после перезапуска

При открытии базы:

1. открыть файл;

2. прочитать header;

3. пройти по записям;

4. восстановить offsets;

5. восстановить primary index;

6. пропустить удалённые записи;

7. проверить checksum.

## Шаг 4.7 — Добавить тест перезапуска

Сценарий:

```
1. Открыть базу.
2. Записать 100 строк.
3. Закрыть базу.
4. Создать новый объект Database.
5. Открыть тот же каталог.
6. Прочитать все строки.
7. Проверить данные.
```

## Результат Phase 4

Данные переживают:

* завершение PHP-процесса;

* создание нового экземпляра Database;

* повторное открытие каталога базы.

# 11. Phase 5 — Primary key and indexes

Сначала поиск может делать полный scan.

```
find(id)
  ↓
прочитать все записи
  ↓
найти нужный id
```

Это просто, но медленно.

## Шаг 5.1 — Реализовать `PrimaryIndex`

Для начала можно использовать hash index:

PHP

```
final class PrimaryIndex
{
    /**
     * @var array<int, RecordPointer>
     */
    private array $entries = [];
}
```

## Шаг 5.2 — Обновлять индекс при вставке

При вставке:

1. проверить уникальность ID;

2. записать строку;

3. получить offset;

4. добавить offset в индекс.

## Шаг 5.3 — Читать запись через индекс

Алгоритм:

```
find(id)
  ↓
index[id]
  ↓
RecordPointer
  ↓
read(offset, length)
  ↓
decode()
  ↓
Row
```

## Шаг 5.4 — Восстанавливать индекс

После открытия файла:

1. прочитать записи;

2. определить ID;

3. определить состояние записи;

4. добавить последнюю актуальную запись в индекс.

## Шаг 5.5 — Добавить benchmark

Сравнить:

```
100 rows
1 000 rows
10 000 rows
100 000 rows
```

Сравнить:

* full scan;

* hash index.

Измерять:

* average lookup time;

* p95;

* p99;

* количество операций чтения;

* memory usage.

## Шаг 5.6 — Добавить secondary index

После primary index можно реализовать:

PHP

```
$users->createIndex('email');
```

Пример структуры:

````
email@example.com → [1, 15, 20]
```

Пока достаточно индекса одного поля.

---

## Результат Phase 5

Поддерживаются:

- быстрый поиск по primary key;
- восстановление индекса;
- простой secondary index;
- benchmark поиска.

---

# 12. Phase 6 — Update and delete

В append-only storage обновление и удаление требуют отдельной модели.

---

## Шаг 6.1 — Реализовать update через новую запись

При обновлении:

1. найти старую запись;
2. создать новую версию;
3. добавить её в конец файла;
4. обновить index;
5. пометить старую запись как устаревшую.

---

## Шаг 6.2 — Ввести состояние записи

Возможные состояния:

~~~text
ACTIVE
DELETED
REPLACED
````

Или через record type:

```
INSERT
UPDATE
DELETE
```

## Шаг 6.3 — Реализовать tombstone

Удаление может записываться как tombstone:

```
DELETE key=10
```

При восстановлении:

* запись с ID 10 считается удалённой;

* старая версия больше не видна.

## Шаг 6.4 — Добавить compaction

Append-only файл со временем растёт.

Compaction:

1. прочитать актуальные записи;

2. создать новый файл;

3. записать только живые записи;

4. построить новый индекс;

5. заменить старый файл.

## Шаг 6.5 — Защититься от повреждения во время compaction

Минимальная схема:

````
data.db
data.db.compacting
data.db.backup
```

Порядок:

1. создать временный файл;
2. записать данные;
3. выполнить flush;
4. выполнить fsync;
5. переименовать старый файл;
6. переименовать новый файл;
7. удалить backup после успешного завершения.

---

## Шаг 6.6 — Тесты compaction

Проверить:

- данные не теряются;
- удалённые записи не возвращаются;
- offsets обновляются;
- размер файла уменьшается;
- база открывается после compaction.

---

## Результат Phase 6

Поддерживаются:

- update;
- delete;
- tombstones;
- append-only versions;
- compaction.

---

# 13. Phase 7 — Query API

До SQL нужно сделать удобный PHP API.

---

## Шаг 7.1 — Реализовать `QueryBuilder`

Пример:

~~~php
$users
    ->query()
    ->where('age', '>', 30)
    ->get();
````

## Шаг 7.2 — Поддержать операторы

Минимальный набор:

```
=
!=
>
>=
<
<=
```

## Шаг 7.3 — Поддержать несколько условий

PHP

```
$users
    ->query()
    ->where('age', '>', 30)
    ->where('active', '=', true)
    ->get();
```

На первом этапе все условия можно объединять через `AND`.

## Шаг 7.4 — Добавить сортировку

PHP

```
$users
    ->query()
    ->orderBy('age', 'desc')
    ->get();
```

Поддержать:

* ascending;

* descending;

* одно поле;

* затем несколько полей.

## Шаг 7.5 — Добавить limit и offset

PHP

```
$users
    ->query()
    ->orderBy('id')
    ->limit(20)
    ->offset(40)
    ->get();
```

## Шаг 7.6 — Добавить first и count

PHP

```
$user = $users
    ->query()
    ->where('id', '=', 10)
    ->first();

$count = $users
    ->query()
    ->where('active', '=', true)
    ->count();
```

## Шаг 7.7 — Добавить explain

Пример:

PHP

```
$users
    ->query()
    ->where('id', '=', 10)
    ->explain();
```

Результат:

```
Plan:
- use primary index
- read 1 record
- no sort
- no full scan
```

## Результат Phase 7

Появляется удобный API для запросов без SQL.

# 14. Phase 8 — SQL lexer

Теперь можно добавить небольшой SQL-слой.

Не нужно реализовывать весь SQL.

## Шаг 8.1 — Определить поддерживаемый SQL

Минимальный SQL:

SQL

```
CREATE TABLE users (
    id INT PRIMARY KEY,
    name TEXT,
    age INT
);
```

SQL

```
INSERT INTO users (id, name, age)
VALUES (1, 'Tanat', 40);
```

SQL

```
SELECT * FROM users;
```

SQL

```
SELECT * FROM users WHERE id = 1;
```

SQL

```
UPDATE users
SET age = 41
WHERE id = 1;
```

SQL

```
DELETE FROM users
WHERE id = 1;
```

## Шаг 8.2 — Создать token types

Минимальные токены:

```
SELECT
INSERT
UPDATE
DELETE
CREATE
TABLE
FROM
WHERE
VALUES
SET
PRIMARY
KEY
AND
ORDER
BY
LIMIT
IDENTIFIER
STRING
NUMBER
COMMA
LPAREN
RPAREN
EQUAL
GREATER
LESS
SEMICOLON
ASTERISK
EOF
```

## Шаг 8.3 — Создать `Lexer`

PHP

```
interface Lexer
{
    /**
     * @return list<Token>
     */
    public function tokenize(string $sql): array;
}
```

## Шаг 8.4 — Реализовать lexer постепенно

Порядок:

1. пробелы;

2. идентификаторы;

3. ключевые слова;

4. числа;

5. строки;

6. знаки пунктуации;

7. операторы;

8. ошибки.

## Шаг 8.5 — Тестировать lexer

Пример:

SQL

```
SELECT id, name FROM users WHERE age >= 30;
```

Ожидаемые токены:

```
SELECT
IDENTIFIER(id)
COMMA
IDENTIFIER(name)
FROM
IDENTIFIER(users)
WHERE
IDENTIFIER(age)
GREATER_OR_EQUAL
NUMBER(30)
SEMICOLON
EOF
```
