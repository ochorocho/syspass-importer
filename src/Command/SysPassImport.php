<?php

// src/Command/CreateUserCommand.php
namespace App\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use ParseCsv\Csv;
use Symfony\Component\Console\Helper\ProgressBar;

class SysPassImport extends Command
{
    protected static $defaultName = 'syspass:import';

    protected function configure()
    {
        $this->setDescription('Import Accounts into SysPass')->setHelp('This command will import accounts from commandline');
        $this->addArgument('url', InputArgument::REQUIRED, 'Base URL');
        $this->addArgument('password', InputArgument::REQUIRED, 'API password');
        $this->addArgument('token', InputArgument::REQUIRED, 'API token');
        $this->addArgument('file', InputArgument::REQUIRED, 'CSV file to import');
        $this->addOption('group-id', 'g', InputOption::VALUE_REQUIRED, 'CSV file to import', 1);
        $this->addOption('failure', 'e', InputOption::VALUE_REQUIRED, 'Location of export file', './failed-import');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if (is_dir($input->getOption('failure'))) {
            $output->writeln(sprintf('Directory "%s" already exists ...', $input->getOption('failure')));
        } else {
            mkdir($concurrentDirectory = $input->getOption('failure'), 0777, true);
        }

        $csv = new Csv();
        $csv->delimiter = ';';
        $csv->parse($input->getArgument('file'));

        $clients = $this->getClients($input);
        $categories = $this->getCategories($input);

        /**
         * Build array of categories/clients to create
         */
        $clientToCreate = [];
        $categoriesToCreate = [];
        foreach ($csv->data as $item) {
            $clientToCreate[] = $item['client'];
            $categoriesToCreate[] = $item['category'];
        }

        $clientToCreate = array_unique($clientToCreate);
        $categoriesToCreate = array_unique($categoriesToCreate);

        $output->writeln('<info>Import Clients ...</info>');

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% -- %message%');

        $clientsProgress = new ProgressBar($output, count($clientToCreate));
        $clientsProgress->setFormat('custom');

        /**
         * Create clients
         */
        foreach ($clientToCreate as $item) {
            $clientsProgress->setMessage('Processing client ' . $item);
            $clientsProgress->advance();

            if(!in_array($item, $clients, true)) {
                $client = $this->createClient($input, $item);

                if (!empty($client->error)) {
                    $csv = new Csv();
                    $csv->delimiter = ";";
                    $csv->enclose_all = true;
                    $csv->save($input->getOption('failure') . DIRECTORY_SEPARATOR . 'accounts.csv', array(array($item, $client->error->message, $client->error->data)), true);
                }
            }
        }

        $output->writeln("\n<info>Import Categories ...</info>");

        $categoriesProgress = new ProgressBar($output, count($categoriesToCreate));
        $categoriesProgress->setFormat('custom');

        /**
         * Create categories
         */
        foreach ($categoriesToCreate as $item) {
            $categoriesProgress->setMessage('Processing category ' . $item);
            $categoriesProgress->advance();

            if(!in_array($item, $categories, true)) {
                $category = $this->createCategory($input, $item);

                if(!empty($category->error)) {
                    $csv = new Csv();
                    $csv->delimiter = ";";
                    $csv->enclose_all = true;
                    $csv->save($input->getOption('failure') . DIRECTORY_SEPARATOR . 'accounts.csv', array(array($item, $category->error->message, $category->error->data)), true);
                }
            }
        }

        $clients = $this->getClients($input);
        $categories = $this->getCategories($input);

        $output->writeln("\n<info>Import Accounts ...</info>");

        $accountsProgress = new ProgressBar($output, count($csv->data));
        $accountsProgress->setFormat('custom');

        foreach ($csv->data as $item) {
            $accountsProgress->setMessage('Processing account ' . $item['name']);
            $accountsProgress->advance();

            $client = array_search($item['client'], $clients);
            $category = array_search($item['category'], $categories);

            $accountValues = [
                'name' => $item['name'],
                'categoryId' => $category,
                'clientId' => $client,
                'pass' => $item['password'],
                'userGroupId' => $input->getOption('group-id'),
                'login' => $item['login'],
                'url' => $item['url'],
                'notes' => $item['notes'],
            ];

            $client = $this->createAccount($input, $accountValues);

            if(!empty($client->error)) {
                $csv = new Csv();
                $csv->delimiter = ";";
                $csv->enclose_all = true;
                $csv->save($input->getOption('failure') . DIRECTORY_SEPARATOR . 'accounts.csv', array(array($item['name'], $item['category'], $item['client'], $item['login'], $item['password'], $item['url'], $client->error->message, $client->error->data)), true);
            }
        }

        return 0;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function getClients($input) {
        $clients = $this->apiRequest($input, "client/search", ['count' => 10000]);

        return $this->resultToArray($clients->result->result);
    }

    /**
     * @param InputInterface $input
     * @param string $name
     * @return array
     */
    protected function createClient($input, $name) {
        $client = $this->apiRequest($input, "client/create", ['name' => $name]);

        return $client;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function getCategories($input) {
        $categories = $this->apiRequest($input, "category/search", ['count' => 10000]);

        return $this->resultToArray($categories->result->result);
    }

    /**
     * @param InputInterface $input
     * @param string $name
     * @return array
     */
    protected function createCategory($input, $name) {
        $category = $this->apiRequest($input, "category/create", ['name' => $name]);

        return $category;
    }

    /**
     * @param InputInterface $input
     * @param array $values
     * @return array
     */
    protected function createAccount($input, $values = []) {
        $category = $this->apiRequest($input, "account/create", $values);

        return $category;
    }

    /**
     * @param InputInterface $input
     * @param string $method
     * @param array $params
     */
    protected function apiRequest(InputInterface $input, $method, $params = [])
    {
        $defaultParams = [
            'authToken' => $input->getArgument('token'),
            'tokenPass' => $input->getArgument('password'),
        ];
        $mergedParams = array_merge($defaultParams, $params);

        $body = [
            'jsonrpc' => "2.0",
            'method' => $method,
            'params' => $mergedParams,
            'id' => 1
        ];

        $client = new Client();
        $response = $client->request(
            'POST',
            $input->getArgument('url') . DIRECTORY_SEPARATOR . 'api.php',
            [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($body)
            ]
        );

        $json = $response->getBody()->getContents();

        return json_decode($json);
    }

    /**
     * @param $result
     * @return array
     */
    protected function resultToArray($result)
    {
        $data = [];
        foreach ($result as $item) {
            $data[$item->id] = $item->name;
        }
        return $data;
    }
}
