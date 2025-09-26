<?php
session_start();
ob_start();

// Required Config Files
require_once('../../lib/config/config.php');

// Required Libraries
require_once('../../lib/helpers/urlhelpers.php');
require_once('../../lib/database/databaseops.php');
require_once('../../services/shipway_api.php');
require_once('../../services/aisensy_api.php'); // 👈 Add this line

// Create Objects
$url = new UrlHelpers();
$database = new DatabaseOps();

// Create DB Connection
$connStatus = $database->createConnection();

// Generate Order Number
$_SESSION['ordernum'] = date('ymdhis') . rand(1000, 9999);

// If Cart Exists
if (isset($_SESSION['cart'])) {
    if ($connStatus) {

        // Safely get values with fallbacks
        $fullname = htmlspecialchars($_POST['fullname'] ?? '');
        $email = htmlspecialchars($_POST['email'] ?? '');
        $phone = htmlspecialchars($_POST['phone'] ?? '');
        $company = htmlspecialchars($_POST['company'] ?? '');
        $address = htmlspecialchars($_POST['address'] ?? '');
        $city = htmlspecialchars($_POST['city'] ?? '');
        $postcode = htmlspecialchars($_POST['pincode'] ?? '');
        $country = htmlspecialchars($_POST['country'] ?? '');
        $region = htmlspecialchars($_POST['state'] ?? '');
        $user_id = intval($_SESSION['user_id'] ?? 0);
        $cart_total = floatval($_SESSION['cart_totalamt'] ?? 0);
        $tax = floatval($_SESSION['enabletaxes'] ?? 0);
        $order_num = $_SESSION['ordernum'];
        $order_date = date('Y-m-d');
        $cart_details = json_encode($_SESSION['cart']);
        $payment_mode = htmlspecialchars($_POST['payment_mode'] ?? 'prepaid');

        // Insert order into DB
        $insertQuery = "INSERT INTO `orders` (
            `order_num`, `order_date`, `order_details`, `order_total`, `order_tax`,
            `order_status`, `order_userid`, `order_fullname`, `order_email`, `order_phone`,
            `order_company`, `order_address`, `order_city`, `order_postcode`, `order_country`,
            `order_state`, `order_paystatus`
        ) VALUES (
            '$order_num', '$order_date', '" . json_encode($_SESSION['cart']) . "', $cart_total, $tax,
            'Pending', $user_id, '$fullname', '$email', '$phone',
            '$company', '$address', '$city', '$postcode', '$country',
            '$region', 0
        )";

        $resultcartdata = $database->runQuery($insertQuery);

        // 🔹 Store in abandoned_orders table immediately (order_paystatus = 0)
        if ($resultcartdata !== false) {
            $insertAbandoned = "INSERT INTO `abandoned_orders` (
                `order_num`, `order_date`, `order_details`, `order_total`, `order_tax`,
                `order_status`, `order_userid`, `order_fullname`, `order_email`, `order_phone`,
                `order_company`, `order_address`, `order_city`, `order_postcode`, `order_country`,
                `order_state`, `order_paystatus`
            ) VALUES (
                '$order_num', '$order_date', '$cart_details', $cart_total, $tax,
                'Pending', $user_id, '$fullname', '$email', '$phone',
                '$company', '$address', '$city', '$postcode', '$country',
                '$region', 0
            )";
            $database->runQuery($insertAbandoned);
        }

        if ($resultcartdata !== false) {
            $ttlamtofcart = floatval($_SESSION['cart_totalamt'] ?? 0);

            // Prepare products array in Shipway's required format
            $products = [];
            foreach ($_SESSION['cart'] as $item) {
                $prodId = $item['product_id'];
                $qty = $item['qty'];

                $res = $database->getData("SELECT * FROM products WHERE prod_id = '$prodId' ");
                if ($res && mysqli_num_rows($res) > 0) {
                    $p = mysqli_fetch_assoc($res);
                    $price = $p['prod_saleprice'] > 0 ? $p['prod_saleprice'] : $p['prod_regularprice'];

                    $products[] = [
                        "product" => htmlspecialchars($p['prod_title']),
                        "price" => strval($price),
                        "product_code" => $p['prod_sku'] ?? $p['prod_id'],
                        "product_quantity" => strval($qty),
                        "discount" => "0",
                        "tax_rate" => "5",
                        "tax_title" => "IGST"
                    ];
                }
            }



            // Prepare Shipway request body (common for COD + Prepaid success page)
            $firstName = strtok($fullname, ' ');
            $lastName = trim(str_replace($firstName, '', $fullname));

            $data = [
                "order_id" => $order_num,
                "ewaybill" => "",
                "products" => $products,
                "discount" => "0",
                "shipping" => "0",
                "order_total" => strval($ttlamtofcart),
                "gift_card_amt" => "0",
                "taxes" => strval($tax),
                "payment_type" => strtolower($payment_mode) === 'cod' ? "C" : "P",
                "email" => $email,
                "billing_address" => $address,
                "billing_address2" => $company,
                "billing_city" => $city,
                "billing_state" => $region,
                "billing_country" => $country,
                "billing_firstname" => $firstName,
                "billing_lastname" => $lastName,
                "billing_phone" => $phone,
                "billing_zipcode" => $postcode,
                "billing_latitude" => "0",
                "billing_longitude" => "0",
                "shipping_address" => $address,
                "shipping_address2" => $company,
                "shipping_city" => $city,
                "shipping_state" => $region,
                "shipping_country" => $country,
                "shipping_firstname" => $firstName,
                "shipping_lastname" => $lastName,
                "shipping_phone" => $phone,
                "shipping_zipcode" => $postcode,
                "shipping_latitude" => "0",
                "shipping_longitude" => "0",
                "order_weight" => "110",
                "box_length" => "20",
                "box_breadth" => "15",
                "box_height" => "10",
                "order_date" => date("Y-m-d H:i:s"),
            ];
            $data['products'] = $products;
            // Payment handling
            if (strtolower($payment_mode) === 'cod') {
                // ✅ COD Orders → Call Shipway immediately
                $response = shipwayApiPost(
                    'https://app.shipway.com/api/v2orders',
                    $data,
                    'info@leatherplus.in',
                    'y983VSB2Tn34tW3xv0u4687rVk1keKq8'
                );
                // file_put_contents(__DIR__ . "/shipway_debug.log", print_r($response, true), FILE_APPEND);

                $aisensy_data = [
                    "apiKey" => "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6IjY4YjZhYzcyZGZkZGU0MGMzMWNlZGM3ZSIsIm5hbWUiOiJTdGFyIE9ubGluZSBJbmMiLCJhcHBOYW1lIjoiQWlTZW5zeSIsImNsaWVudElkIjoiNjhiNmFjNzJkZmRkZTQwYzMxY2VkYzc5IiwiYWN0aXZlUGxhbiI6IkZSRUVfRk9SRVZFUiIsImlhdCI6MTc1NjgwMjE2Mn0.u9B4-RskS_j2QezAZt09rmI7O7-x76t-fB_lX7HCpws", // 👈 Replace with your actual API key
                    "campaignName" => "Leatherplus",
                    "destination" => "+91" . $phone, // Add country code if not present in $phone
                    "userName" => $firstName,
                    "templateParams" => [
                        $firstName,
                        "Leather Plus"
                    ],
                    "media" => [
                        "url" => "https://leatherplus.in/views/app/assets/images/Desktop_Banner.jpg",
                        "filename" => "file"
                    ]
                ];

                $aisensy_response = aisensyApiPost($aisensy_data);
                if (isset($response['error'])) {
                    error_log("Shipway Push Order Error: " . ($response['message'] ?? 'Unknown error'));
                } else if (isset($aisensy_response['error'])) {
                    error_log("Aisensy API Error: " . ($aisensy_response['message'] ?? 'Unknown error'));

                } else {
                    $awb = $response['awb'] ?? null;
                    if ($awb) {
                        $database->runQuery("UPDATE `orders` SET `awb` = '$awb', `order_status` = 'Processing' WHERE `order_num` = '$order_num'");
                    }
                }

                $database->runQuery("UPDATE `orders` SET `order_status` = 'Pending', `order_paystatus` = 2 WHERE `order_num` = '$order_num'");
                $_SESSION['cart'] = [];
                $_SESSION['cart_totalamt'] = 0;
                unset($_SESSION['enabletaxes']);
                header('location:' . $url->baseUrl('views/app/thank-you?order=' . $order_num));

            } else {
                // ✅ Prepaid Orders → Do not call Shipway here
                // Call Shipway later in payment success page only if payment is successful
                if (strtolower($country) === "india") {
                    header('location:' . $url->baseUrl("rzp/pay.php?rcpt=$order_num&amt=$ttlamtofcart&name=$fullname&phone=$phone&email=$email"));
                } else {
                    header('location:' . $url->baseUrl("paypal/index.php?rcpt=$order_num&amt=$ttlamtofcart&name=$fullname&phone=$phone&email=$email"));
                }
            }
        } else {
            error_log("Order insertion failed for order_num: $order_num");
            header('location:' . $url->baseUrl('app/cart?m=nc'));
        }
    } else {
        header('location:' . $url->baseUrl('app/cart'));
    }
}
?>