<?php

// src/Command/CreateUserCommand.php
namespace App\Command;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use ParseCsv\Csv;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;

class SysPassImport extends Command
{
    /**
     * @var array
     */
    protected  $categories = [];

    /**
     * @var array
     */
    protected  $clients = [];

    protected static $defaultName = 'syspass:import';

    protected function configure()
    {
        $this->setDescription('Import Accounts into SysPass')->setHelp('This command will import accounts from commandline');
        $this->addArgument('url', InputArgument::REQUIRED, 'Base URL');
        $this->addArgument('password', InputArgument::REQUIRED, 'API password');
        $this->addArgument('token', InputArgument::REQUIRED, 'API token');
        $this->addArgument('file', InputArgument::REQUIRED, 'CSV file to import');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $csv = new Csv();
        $csv->delimiter = ";";
        $csv->parse($input->getArgument('file'));

        /**
         * Import CSV
         */

        $clientToCreate = [];
        $categoriesToCreate = [];
        foreach ($csv->data as $item) {
            $clientToCreate[] = $item['client'];
            $categoriesToCreate[] = $item['category'];
        }

        foreach ($clientToCreate as $item) {
            $client = $this->createClient($input, $item);
            if (!empty($client->error)) {
                $output->writeln("Could not create client: " . $client->error->message);
            } else {
                $output->writeln("Created client: " . $client->result->itemId . ' ' . $client->result->result->name);
            }
        }

        foreach ($categoriesToCreate as $item) {
            $category = $this->createCategory($input, $item);
            if(!empty($category->error)) {
                $output->writeln("Could not create category: " . $category->error->message);
            } else {
                $output->writeln("Created category: " . $category->result->itemId . ' ' . $category->result->result->name);
            }
        }

        $clients = $this->getClients($input);
        $categories = $this->getCategories($input);

        foreach ($csv->data as $item) {

            $client = array_search($item['client'], $clients);
            $category = array_search($item['category'], $categories);

            $accountValues = [
                'name' => $item['name'],
                'categoryId' => $category,
                'clientId' => $client,
                'pass' => $item['password'],
                'userGroupId' => 2,
                'login' => $item['login'],
                'url' => $item['url'],
                'notes' => $item['notes'],
            ];

            $client = $this->createAccount($input, $accountValues);
            if(empty($client->error)) {
                $output->writeln("Imported: " . $item['name'] . " (" . $item['url'] . ")");
            } else {
                $csv = new Csv();
                $csv->delimiter = ";";
                $csv->enclose_all = true;
                $csv->save('./data.csv', array(array($item['name'], $item['category'], $item['client'], $item['login'], $item['password'], $item['url'], $client->error->message, $client->error->data)), true);

                $output->writeln("<error>Failed: " . $item['name'] . " (" . $item['login'] . ")" . '</error>');
            };
        }

        /**
         * Get Categories
         */
//        $categories = $this->getCategories($input);
//        var_dump($categories);

        /**
         * Create new category
         */
//        $newCat = $this->createCategory($input, 'NeueCategory');
//        var_dump($newCat);

        /**
         * Search clients
         */
//        $clients = $this->getClients($input);
//        var_dump($clients);

        /**
         * Create client
         */
//        $createClient = $this->createClient($input, 'NEW TESTTETaaaaS');
//        var_dump(is_null($createClient->error));

        /**
         * Create account
         */
//        $accountValues = [
//                'name' => '#########',
//                'categoryId' => 80,
//                'clientId' => 109,
//                'pass' => '#########',
//                'userGroupId' => 2,
//                'login' => '#########',
//                'url' => 'http://www.google.de',
//                'notes' => '#########',
//            ];
//        $huhu = $this->createAccount($input, $accountValues);

        return 0;
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    protected function getClients($input) {
        $clients = $this->apiRequest($input, "client/search");
        $data = [];

        foreach ($clients->result->result as $client) {
            $data[$client->id] = $client->name;
        }
        $this->clients = $data;

        return $data;
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
        $categories = $this->apiRequest($input, "category/search");
        $data = [];

        /** @var TYPE_NAME $category */
        foreach ($categories->result->result as $category) {
            $data[$category->id] = $category->name;
        }
        $this->categories = $data;

        return $data;
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
}
