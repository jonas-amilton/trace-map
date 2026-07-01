# trace-map.php — PHP Call-Trace Mapper (dependency-free)

Análise estática determinística de relações entre métodos em projetos PHP/Laravel.
**Zero dependências** — usa apenas `token_get_all()` nativo do PHP. Sem Composer, sem IA, sem `rg`.

```bash
php trace-map.php --symbol="App\Http\Controllers\GameController::store"
```

```
└── App\Http\Controllers\GameController::store(GameRequest $request)
    file: app/Http/Controllers/GameController.php:10
├── calls App\Http\Requests\GameRequest::validated()
│   line: app/Http/Controllers/GameController.php:12 [unresolved]
│   [receiver resolved from typed method parameter]
├── calls App\Models\Game::create($form)
│   line: app/Http/Controllers/GameController.php:14 [unresolved]
│   [receiver resolved from imported static class]
└── calls App\Models\Game::toResource()
    line: app/Http/Controllers/GameController.php:18 [unresolved]
    [receiver inferred from assignment: $game = Game::create(...)]
```

## Uso

```bash
# Inspecionar um método
php trace-map.php --symbol="Namespace\\Class::method" [--depth=5]

# Analisar stack trace de um log Laravel
php trace-map.php --log=storage/logs/laravel.log

# Pipe
cat storage/logs/laravel.log | php trace-map.php

# Versão
php trace-map.php --version

# Self-test
php trace-map.php --self-test
```

## Opções

| Flag | Descrição | Default |
|---|---|---|
| `--symbol=FQCN::method` | Inspeciona um método sem precisar de log | — |
| `--log=FILE` | Arquivo de log. Se omitido, lê STDIN | — |
| `--path=DIR` | Raiz do projeto | diretório atual |
| `--depth=N` | Profundidade máxima da árvore (1–12) | 5 |
| `--include-tests` | Inclui `tests/` na indexação | não |
| `--version` | Versão e sai | — |
| `--self-test` | Testes internos (fixture temporária) | — |
| `--help` | Ajuda | — |

## O que resolve

- **Parâmetros tipados**: `store(GameRequest $request)` → receiver `GameRequest`
- **Nullable/union types**: `?GameRequest`, `GameRequest\|OtherRequest` → ambíguo com candidatos
- **Imports comuns e agrupados**: `use App\Models\Game`, `use App\Models\{Game, Player as P}`
- **Inferência de variável**: `$game = Game::create($form)` → receiver `Game`
- **`new` expression**: `$svc = new PaymentService()` → receiver `PaymentService`
- **Helpers Laravel**: `app(Foo::class)`, `resolve(Foo::class)`
- **Propriedades tipadas**: `$this->payment->process()` via `private PaymentService $payment`
- **`$this->method()`**: resolve contra a classe atual (inclui herança/traits indexados)
- **`ClassName::method()`**: resolve `ClassName` via namespace + imports
- **Bindings Laravel**: `$this->app->bind(Interface::class, Concrete::class)`

## Níveis de confiança na saída

| Tag | Significado |
|---|---|
| `[confirmed]` | Resolução direta: sintaxe, tipo explícito, ou classe estática |
| `[inferred]` | Atribuição local, binding Laravel, ou única implementação |
| `[candidate]` | Mais de um alvo possível (ex: union type) |
| `[unresolved]` | Não foi possível resolver com segurança |

## O que NÃO faz

- **Não inventa relações** — quando não há evidência suficiente, reporta como `[unresolved]`
- **Não indexa `vendor/`** — métodos do Laravel/frameworks aparecem como `[unresolved]` mas com receiver correto
- **Não depende de IA, Composer, ou pacotes externos**
- **Não rastreia closures, macros, reflection, ou classes geradas em runtime**

## Diretórios ignorados

`.git`, `vendor`, `node_modules`, `storage`, `bootstrap/cache`, `coverage`, `.idea`, `.vscode`, `public/build`, `dist`, `build`, e o próprio `trace-map.php`

## Requisitos

PHP 7.2+ com `token_get_all()` (built-in, sempre disponível). Polyfills internos para `str_starts_with`/`str_contains` garantem compatibilidade sem dependências externas.

## Licença

MIT
