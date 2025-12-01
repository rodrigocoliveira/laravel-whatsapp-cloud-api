# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package Overview

Laravel package for integrating WhatsApp Cloud API. Handles webhook connections, media downloads, message sending, and processing incoming messages.

## Development Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/pest

# Run a single test file
./vendor/bin/pest tests/Feature/ExampleTest.php

# Run a specific test by name
./vendor/bin/pest --filter "test name"

# Code style check
./vendor/bin/pint --test

# Code style fix
./vendor/bin/pint

# Static analysis
./vendor/bin/phpstan analyse
```

## Architecture

### Service Provider
The package registers via a Laravel service provider that publishes config, routes, and migrations.

### Core Components

- **WhatsApp Client**: HTTP client wrapper for WhatsApp Cloud API endpoints (sending messages, uploading media, managing templates)
- **Webhook Controller**: Handles incoming webhook verification (GET) and message events (POST) from Meta
- **Message Handlers**: Process different incoming message types (text, media, interactive, location, etc.)
- **Media Service**: Downloads and stores media files from WhatsApp servers
- **DTOs**: Data transfer objects for messages, contacts, and media

### Configuration
- `config/whatsapp.php`: API credentials, webhook verification token, phone number ID, business account ID

### Routes
- Webhook endpoint for receiving messages from Meta
- Optional routes for message status callbacks

### Events
Package dispatches Laravel events for incoming messages that applications can listen to.
