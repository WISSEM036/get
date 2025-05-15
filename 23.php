<?php
// Connexion à la base de données
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "gps_tracker";

// Créer une connexion
$conn = new mysqli($servername, $username, $password, $dbname);

// Vérifier la connexion
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Vérifier que toutes les données requises sont présentes
$required_params = ['imei', 'latitude', 'longitude', 'temperature', 'accel_x', 'accel_y', 'accel_z'];
foreach ($required_params as $param) {
    if (!isset($_GET[$param])) {
        die("Error: Missing parameter '$param' in the request.");
    }
}

// Nettoyer et valider les données
$imei = preg_replace('/[^0-9]/', '', $_GET['imei']);
if (strlen($imei) !== 15) {
    die("Error: Invalid IMEI format (must be 15 digits)");
}

$new_latitude = floatval($_GET['latitude']);
$new_longitude = floatval($_GET['longitude']);
$temperature = floatval($_GET['temperature']);
$accel_x = floatval($_GET['accel_x']);
$accel_y = floatval($_GET['accel_y']);
$accel_z = floatval($_GET['accel_z']);

// Fonction pour calculer la distance en utilisant la formule de Haversine
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; // Rayon de la Terre en km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth_radius * $c; // Distance en km
}

// Vérifier si l'IMEI existe déjà dans la table device
$check_sql = "SELECT id, latitude, longitude, recorded_at FROM device WHERE imei = ? LIMIT 1";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("s", $imei);
$check_stmt->execute();
$check_stmt->store_result();

$speed = 0; // Initialiser la vitesse à 0

if ($check_stmt->num_rows > 0) {
    // Récupérer les anciennes coordonnées et l'heure de la dernière mise à jour
    $check_stmt->bind_result($id, $old_latitude, $old_longitude, $recorded_at);
    $check_stmt->fetch();

    // Calculer la distance entre les anciennes et nouvelles coordonnées
    $distance_km = calculateDistance($old_latitude, $old_longitude, $new_latitude, $new_longitude);

    // Calculer le temps écoulé en heures
    $time_diff_seconds = (new DateTime())->getTimestamp() - (new DateTime($recorded_at))->getTimestamp();
    $time_diff_hours = $time_diff_seconds / 3600;

    // Calculer la vitesse en km/h
    if ($time_diff_hours > 0) {
        $speed = $distance_km / $time_diff_hours;
    }

    // Mettre à jour les données
    $update_sql = "UPDATE device SET 
                  latitude = ?, 
                  longitude = ?, 
                  temperature = ?, 
                  accel_x = ?, 
                  accel_y = ?, 
                  accel_z = ?, 
                  speed = ?, 
                  recorded_at = NOW() 
                  WHERE imei = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ddddddds", $new_latitude, $new_longitude, $temperature, 
                           $accel_x, $accel_y, $accel_z, $speed, $imei);
    
    if ($update_stmt->execute()) {
        echo "Device data updated successfully. Speed: " . round($speed, 2) . " km/h";
    } else {
        echo "Error updating device: " . $update_stmt->error;
    }
    
    $update_stmt->close();
} else {
    // Si l'IMEI n'existe pas: créer une nouvelle entrée
    $insert_sql = "INSERT INTO device 
                  (imei, latitude, longitude, temperature, 
                   accel_x, accel_y, accel_z, speed, recorded_at) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $insert_stmt = $conn->prepare($insert_sql);
    $insert_stmt->bind_param("sdddddds", $imei, $new_latitude, $new_longitude, 
                           $temperature, $accel_x, $accel_y, $accel_z, $speed);
    
    if ($insert_stmt->execute()) {
        echo "New device created and data inserted successfully. Speed: " . round($speed, 2) . " km/h";
    } else {
        echo "Error inserting new device: " . $insert_stmt->error;
    }
    
    $insert_stmt->close();
}

// Fermer les connexions
$check_stmt->close();
$conn->close();
?>