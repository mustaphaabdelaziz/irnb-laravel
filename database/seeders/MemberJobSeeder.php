<?php

namespace Database\Seeders;

use App\Models\MemberJob;
use Illuminate\Database\Seeder;

class MemberJobSeeder extends Seeder
{
    public function run(): void
    {
        $jobs = [
            ['name' => 'Médecin', 'description' => 'Professionnel de la santé qui diagnostique et traite les maladies.'],
            ['name' => 'Ingénieur', 'description' => 'Spécialiste de la conception et du développement de solutions techniques.'],
            ['name' => 'Enseignant', 'description' => 'Personne qui enseigne dans une école ou une université.'],
            ['name' => 'Commerçant', 'description' => 'Personne qui vend des biens ou des services.'],
            ['name' => 'Étudiant', 'description' => 'Personne qui suit une formation académique ou professionnelle.'],
            ['name' => 'Avocat', 'description' => 'Professionnel du droit qui défend les intérêts de ses clients devant les juridictions.'],
            ['name' => 'Architecte', 'description' => 'Spécialiste de la conception et de la planification de bâtiments et d\'infrastructures.'],
            ['name' => 'Informaticien', 'description' => 'Personne qui crée et maintient des applications logicielles.'],
            ['name' => 'Comptable', 'description' => 'Professionnel qui gère les finances, les comptes et les déclarations fiscales d\'une entreprise.'],
            ['name' => 'Chef de projet', 'description' => 'Personne responsable de la gestion de projets de manière efficace et dans les délais.'],
            ['name' => 'Designer', 'description' => 'Spécialiste de la création visuelle et graphique pour les produits, services ou marques.'],
            ['name' => 'Plombier', 'description' => 'Technicien chargé de l\'installation et de la réparation des systèmes de plomberie.'],
            ['name' => 'Electricien', 'description' => 'Technicien spécialisé dans les installations électriques et la réparation des équipements électriques.'],
            ['name' => 'Cuisinier', 'description' => 'Professionnel de la préparation des repas dans des restaurants ou d\'autres établissements alimentaires.'],
            ['name' => 'Jardinier', 'description' => 'Personne qui s\'occupe de l\'entretien des espaces extérieurs et des jardins.'],
            ['name' => 'Photographe', 'description' => 'Personne qui prend des photos à des fins artistiques ou commerciales.'],
            ['name' => 'Journaliste', 'description' => 'Professionnel chargé de la collecte, de la rédaction et de la diffusion de l\'information.'],
            ['name' => 'Infirmier', 'description' => 'Professionnel de la santé qui prodigue des soins aux patients sous la direction d\'un médecin.'],
            ['name' => 'Vétérinaire', 'description' => 'Médecin spécialisé dans la santé animale, notamment les soins aux animaux domestiques.'],
            ['name' => 'Bibliothécaire', 'description' => 'Professionnel chargé de la gestion et de l\'organisation des bibliothèques.'],
            ['name' => 'Directeur', 'description' => 'Personne en charge de la gestion et de la direction d\'une organisation ou d\'une entreprise.'],
            ['name' => 'Pharmacien', 'description' => 'Spécialiste des médicaments qui conseille les patients et les prescripteurs sur les traitements.'],
        ];

        foreach ($jobs as $job) {
            MemberJob::firstOrCreate(['name' => $job['name']], $job);
        }
    }
}
