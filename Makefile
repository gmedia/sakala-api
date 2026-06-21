.PHONY: setup up down restart shell logs queue reverb lint analyse test check audit

setup:
	composer setup

up:
	./vendor/bin/sail up -d

down:
	./vendor/bin/sail down

restart:
	./vendor/bin/sail restart

shell:
	./vendor/bin/sail shell

logs:
	./vendor/bin/sail logs -f

queue:
	./vendor/bin/sail artisan queue:listen --tries=1 --timeout=0

reverb:
	./vendor/bin/sail artisan reverb:start

lint:
	composer lint

analyse:
	composer analyse

test:
	composer test

check:
	composer check

audit:
	composer audit --locked
