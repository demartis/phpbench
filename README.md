# PHPBench - A Single-File PHP Benchmarking Script

[![PHP Version](https://img.shields.io/badge/PHP-%5E5.6-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-LGPL-green.svg)](https://opensource.org/licenses/LGPL-3.0)
[![Code Quality](https://img.shields.io/scrutinizer/g/demartis/phpbench/main.svg)](https://scrutinizer-ci.com/g/demartis/phpbench/)
[![Issues](https://img.shields.io/github/issues/demartis/phpbench.svg)](https://github.com/demartis/phpbench/issues)
[![Forks](https://img.shields.io/github/forks/demartis/phpbench.svg)](https://github.com/demartis/phpbench/network/members)
[![Stars](https://img.shields.io/github/stars/demartis/phpbench.svg?style=social)](https://github.com/demartis/phpbench/stargazers)

## Introduction

PHPBench is a single-file PHP benchmarking tool that helps you measure various aspects of your PHP environment's performance, including core PHP operations, I/O operations, randomness generation, and MySQL query handling.

Initially inspired by [PHP Benchmark Script](https://github.com/sergix44/php-benchmark-script), this script has been completely refactored and rewritten.
While the original project provided a great starting point, I found that having **a single self-contained PHP file is often more convenient**. Deploying one file simplifies the process of benchmarking different servers, especially when you have limited access, making maintenance and distribution much easier.

## Features

- **Single-file approach:** All tests and functionality in one PHP file.
- **Variety of benchmarks:** CPU operations, loops, string/array manipulation, MySQL queries, and more.
- **Configurable multiplier:** Quickly scale the complexity of benchmarks.
- **MySQL integration (optional):** Test query performance if `mysqli` is available.
- **Easy extensibility:** Add your own benchmark sets by following the provided examples.

## Requirements

- **PHP 5.6 or higher**  
- The `mysqli` extension for MySQL tests (optional).

## How to Run

1. **Command line (CLI):**
   From your SSH:
   ```bash
   curl https://raw.githubusercontent.com/demartis/phpbench/main/phpbench.php | php
   ```
   You can pass arguments as `--key=value`. For example, adjust the `multiplier` to make the tests run longer or point to a different MySQL server.
   Don't forget the double `--` when running with the pipe, eg: `php -- --multiplier=2 ` :
   ```bash
    curl https://raw.githubusercontent.com/demartis/phpbench/main/phpbench.php | php -- --multiplier=2 
   ``` 

   or locally:
   ```bash
   php phpbench.php --multiplier=2 --mysql_host=127.0.0.1 --mysql_user=root --mysql_password=secret
   ```

3. **Via a web server:**
   Place `phpbench.php` in a web-accessible folder and run:
   ```text
   http://yourserver/phpbench.php?multiplier=2
   ```
   
   Query string parameters will override default settings (see `$default_args params`).
   e.g:
   ```text
   http://yourserver/phpbench.php?multiplier=2&mysql_host=127.0.0.1&mysql_user=root&mysql_password=secret
   ```

## Adding Custom Tests

It's simple to add your own test functions. For example, to add a custom test set named `mytest`:

- Add it to the `$benchmarks` array like:
  ```php
  'mytest' => get_benchmarks_mytest(),
  ```
  so:
  ```php
  $benchmarks = [
    'core'  => get_benchmarks_core(),
    'io'    => get_benchmarks_io(),
    'rand'  => get_benchmarks_rand(),
    'mytest' => get_benchmarks_mytest(),  // <--- your new test
    'mysql' => get_benchmarks_mysql(),
  ];
  ```



- Then define your function:

  ```php
  function get_benchmarks_mytest()
  {
      return [
          'mytest_1' => function ($multiplier = 1, $count = 1000) {
              $count = $count * $multiplier;
              for ($i = 0; $i < $count; $i++) {
                  something(0, $i);
              }
              return $i;
          },
      ];
  }
  ```

Replace `something(0, $i);` with your actual custom code to benchmark.

## License (LGPL)

This project is released under the **LGPL license**. This means:
- You are free to use, modify, and distribute this software.
- If you modify and distribute the code, you must provide the modified source code under the same LGPL terms.
- You can integrate this code into larger projects, even proprietary ones, as long as you adhere to the LGPL requirements regarding modifications to this code.

For more details, see the [LGPL License text](https://www.gnu.org/licenses/lgpl-3.0.html).

## Contributing

Contributions are welcome!  
- Feel free to open **Pull Requests** to add new benchmarks, enhance functionality, or improve the code structure.
- Contact me at **riccado@demartis.it** if you have any questions or need guidance.

Your input and improvements can help make PHPBench more useful for everyone.

----------------------------------------------------------------------------------------

**Author:** Riccardo De Martis (<riccado@demartis.it>)  
**GitHub:** [https://github.com/demartis/phpbench](https://github.com/demartis/phpbench)
