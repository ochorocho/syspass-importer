# SysPass Importer

Import CSV into SysPass using the API.
Use it to push initial data to SysPass.

:warning: Accounts are not verified if they already exist on import

## Setup

```
git clone git@github.com:ochorocho/syspass-importer.git
cd syspass-importer
composer install
```

## Run

```
php ./syspass-import syspass:import [-g|--group-id GROUP-ID] [-e|--failure FAILURE] [--] <url> <password> <token> <file>
```



## Example input [data](sample.csv)

name            | client      | category      | url         | login    | password | notes
--------------- | ----------- | ------------- | ----------- | -------- | -------- | -----------
name of account | client name | category name | example.url | username | pa$$word | description
