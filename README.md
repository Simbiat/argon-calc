# Argon Calculator

Calculate settings for the Argon hashing function to fi a time budget.

```php
new \Simbiat\Argon()->calc(float $max_time_spent, int $min_memory_kib, int $max_memory_kib, int $min_time_cost, int $threads, string $string_to_test, int $max_search_steps)
```

Tuning order: `time_cost` is raised first. Any time budget left over is then spent on memory. This avoids allocating large memory blocks before a working `time_cost` is known.

`$max_time_spent` - Target hash time in seconds. Common recommendations are from 0.1 to 1 second. Default is 0.25.

`$min_memory_kib` - Minimum `memory_cost` in KiB. Default is `47104` (47 MiB) as recommended by [OWASP baseline](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html#argon2id).

`$max_memory_kib` - Hard ceiling for `memory_cost` in KiB. Default is `262144` (256 MiB).
`$min_time_cost` - Minimum `time_cost`. Default is 1 as recommended by OWASP. This is the main knob that will be tuned.

`$threads` - Argon2 parallelism (p). Default 1 as recommended by OWASP. Not auto-tuned, since you also need to significantly increase memory: setting it to 2 will require 2 GiB for `memory_cost` to achieve the same level of security.

`$string_to_test` - Sample string used only for timing. There is a default one, but you use whatever string you want.

`$max_search_steps` - Safety cap: no search loop will run more than these many steps. Default is `64`
