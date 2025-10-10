# Minimalistic, ergonomic PHP toolkit.

<img align="right" src="https://raw.githubusercontent.com/phpgt/webengine/master/logo.png" alt="PHP.GT logo" />

WebEngine is an ergonomic toolkit for building web applications. It follows a static-first approach: development begins using plain HTML files, with PHP introduced only when needed. Dynamic behaviour is handled through server-side DOM manipulation, mirroring well-known client-side techniques.

Web frameworks offer many features, but often come with steep learning curves or imposing rules. The motivation behind this project is the belief that what a framework can offer can be achieved by **eliminating code rather than adding more**.

[Head over to the Github Wiki for documentation](https://github.com/phpgt/webengine/wiki).

***

<a href="https://github.com/PhpGt/WebEngine/actions" target="_blank">
	<img src="https://badge.status.php.gt/webengine-build.svg" alt="PHP.GT/WebEngine build status" />
</a>
<a href="https://coveralls.io/r/phpgt/webengine" target="_blank">
	<img src="https://badge.status.php.gt/webengine-coverage.svg" alt="PHP.GT/WebEngine code coverage" />
</a>
<a href="https://app.codecov.io/gh/phpgt/webengine" target="_blank">
	<img src="https://badge.status.php.gt/webengine-quality.svg" alt="PHP.GT/WebEngine code quality" />
</a>
<a href="https://packagist.org/packages/phpgt/webengine" target="_blank">
	<img src="https://badge.status.php.gt/webengine-version.svg" alt="PHP.GT/WebEngine Composer version" />
</a>
<a href="https://packagist.org/packages/phpgt/webengine" target="_blank">
	<img src="http://img.shields.io/packagist/dm/phpgt/webengine.svg?style=flat-square" alt="PHP.GT/WebEngine download stats" />
</a>
<a href="https://www.php.gt/webengine/" target="_blank">
	<img src="https://badge.status.php.gt/webengine-docs.svg" alt="PHP.GT/WebEngine Website" />
</a>

Features at a glance
--------------------

+ Simple routing: A page's view in `page.html` has optional logic separated within `page.php`
+ Pages made dynamic via server-side DOM Document access
+ HTML templates
+ Database organisation
+ Create web pages or web services (APIs) with the same code structure
+ Preconfigured client-side build steps (SCSS, ES6, etc.)
+ Strong separation of concerns over PHP, HTML, SQL, JavaScript, CSS
+ Preconfigured PHPUnit and Behat test environment
+ Workflow tools to quickly create, integrate and deploy projects

Essential concepts
------------------

### Static first

Start with a static HTML prototype to move fast and remove barriers. Add logic only where needed to turn it into production code, keeping the steps minimal.  

### Build using tech you already know

WebEngine builds on the core technologies of the [World Wide Web](https://en.wikipedia.org/wiki/World_Wide_Web), such as HTML and HTTP. Use familiar tools to get real work done, with helpful enhancements layered on top.

### Drop in tools without fuss

[SCSS parsing](https://github.com/phpgt/webengine/wiki/Client-side-files), [HTML templating](https://github.com/phpgt/webengine/wiki/Templating), [CSRF handling](https://github.com/phpgt/webengine/wiki/CSRF), and other tools are included out of the box. The modular architecture keeps compatibility high, so you can install packages from NPM or Packagist with no configuration.

### Develop locally or in a VM

Scripts are provided to spin up local servers or virtualised environments quickly, without changing your system configuration.

### Community blueprints

Blueprint projects help you start fast. They provide just enough structure and design to get a prototype running, without locking you into a specific style of development or design.

Getting started
---------------

### Getting started developing WebEngine applications

If you are new to WebEngine development, check out the [Quick Start][quick-start] guide in the documentation, or jump straight into [the tutorials][tutorials].

### Getting started contributing to WebEngine

If you are looking to contribute to WebEngine itself, please read the [Contribution guidelines document][contributing].

How to get help
---------------

### Submit an issue

The [Github issue tracker][issues] is used to submit bug reports, feature requests or certain types of technical support requests. If you think something is not working correctly, or the documentation doesn't cover your issue, feel free to open a new issue, describing what you have tried, what you expect, and what went wrong.

It would be helpful if you could create your issue in the appropriate repository - for instance, if the issue/question is regarding using a database in WebEngine, https://github.com/phpgt/Database/issues would be the best place - but it's fine to create the issue on WebEngine's issue tracker, and someone can then move the issue if necessary.

### Chat to a developer

A hands-on dev chat system is currently being planned

[quick-start]: https://github.com/PhpGt/WebEngine/wiki/Quick-start
[tutorials]: https://github.com/PhpGt/WebEngine/wiki/hello-world-tutorial
[contributing]: https://github.com/PhpGt/WebEngine/blob/master/CONTRIBUTING.md
[issues]: https://github.com/PhpGt/WebEngine/issues

# Proudly sponsored by

[JetBrains Open Source sponsorship program](https://www.jetbrains.com/community/opensource/)

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://www.jetbrains.com/community/opensource/)
