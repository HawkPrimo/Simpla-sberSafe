<?php

chdir('..');
require_once('parser/SafeParser.php');
require_once('api/Simpla.php');

$start_time = microtime(true);
$data       = [];

$simpla = new Simpla();
$parser = new ParserMetall();

$mode = $simpla->request->get('mode', 'string');
if ($simpla->request->get('s_pos'))
{
    $start_position = $simpla->request->get('s_pos', 'integer');
}
else
{
    $start_position = 1;
}

$parsers_pages = $simpla->parsers->get_parsers_pages();
$date          = date('Y-m-d H:i:s');

$timestamp = date('Y-m-d H:i:s', strtotime($date));

if ($mode == 'update')
{
    $result = add_products_page($simpla, $parser, $start_position);
    header("Content-type: application/json; charset=UTF-8");
    header("Cache-Control: must-revalidate");
    header("Pragma: no-cache");
    header("Expires: -1");
    print json_encode($result);
}
elseif ($mode == 'update_price')
{
    foreach ($parsers_pages as $pp)
    {
        $product = $parser->getProduct($pp->site_url);
        if (!empty($pp->product_id))
        {
            //$product['product_id']=$pp->product_id;
            $variant                = new stdClass;
            $variants               = $simpla->variants->get_variants(['product_id' => $pp->product_id]);
            $variant->id            = $variants[0]->id;
            $variant->compare_price = $product['price'];
            if (!empty($variant->id))
            {
                $simpla->variants->update_variant($variant->id, $variant);
            }
        }
        $pp->date_parsed = $timestamp;
        if (!empty($pp->product_id))
        {
            $simpla->parsers->update_parsers_pages($pp->id, $pp);
        }
    }
}
else
{
    foreach ($parsers_pages as $pp)
    {
        $product = $parser->getProduct($pp->site_url);
        if (!empty($pp->product_id))
        {
            $product['product_id'] = $pp->product_id;
        }

        $pp->product_id  = simpla_product($product, $simpla);
        $pp->date_parsed = $timestamp;

        if (!empty($pp->product_id))
        {
            $simpla->parsers->update_parsers_pages($pp->id, $pp);
        }
    }
}

function deb($var)
{
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
}

function dd($var)
{
    deb($var);
    die();
}

function simpla_product($parsed_product = [], $simpla)
{
    $product              = new stdClass;
    $product_categories[] = [];
    $product_brands[]     = [];
    $variant              = new stdClass;
    $simpla->db->query("SELECT name FROM s_brands ORDER BY id");
    $all_brands             = $simpla->db->results('name');
    $update_desc            = $simpla->settings->parser_desc;
    $update_attr            = $simpla->settings->parser_attr;
    $update_img             = $simpla->settings->parser_img;
    $update_brands          = $simpla->settings->parser_brands;
    $product->name          = $parsed_product['name'];
    $product->h1_name       = $parsed_product['name'];
    $product->annotation    = '<p><strong> </strong></p>';
    $product->meta_keywords = '';
    $product->visible       = 0;
    $product->url           = $simpla->translit($parsed_product['name']);
    $product->meta_title    = $parsed_product['title'];

    //если обновлять описание то обновляем
    if (!$update_desc)
    {
        $body                      = str_replace("      ", " ", strip_tags((string)$parsed_product['description'][0]));
        $body                      = trim($body);
        $product->body             = "<p style=\"text-align: justify;\">" . $body . "</p>";
        $product->meta_description = $body;
    }

    if (!$update_brands)
    {
        // ищем в названии бренд из доступных на сайте и добавляем id бренда
        $product_brand_name = search_brand_in_name($product->name, $all_brands);
        if (!empty($product_brand_name))
        {
            $query = $simpla->db->placehold("SELECT id FROM __brands WHERE name LIKE BINARY '%$product_brand_name%' LIMIT 1");
            $simpla->db->query($query);
            $brand_id = $simpla->db->result('id');
            if (!empty($brand_id))
            {
                $product->brand_id = (int)$brand_id;
            }
        }
        else
        {
            $product->brand_id = 109;
        }
    }

    // проверяем категорию на наличие и выбираем установленную
    $product_category = $parsed_product['breadcrumbs'][3];
    if (!empty($product_category))
    {
        $simpla->db->query('SELECT category_id FROM __parsers_categories WHERE name=? AND url=? LIMIT 1',
            $product_category['name'], $product_category['url']);
        $product_category_id = $simpla->db->result('category_id');
    }
    if (!empty($product_category_id))
    {
        $category_id = (int)$product_category_id;
    }
    else
    {
        $category_id = 350;
    }
    $product_categories[] = $category_id;

    // Добавления продукта и варианта их обновление
    if (isset($parsed_product['product_id']))
    {
        $product->id = $parsed_product['product_id'];
        $variants    = $simpla->variants->get_variants(['product_id' => $product->id]);
        $variant->id = $variants[0]->id;
    }
    if (empty($product->id))
    {
        $product->id = $simpla->products->add_product($product);
        $product     = $simpla->products->get_product($product->id);
    }
    else
    {
        $simpla->products->update_product($product->id, $product);
    }

    //удаление всех для продукта и добавление категорий к продукту
    if (!empty($product_categories))
    {
        $simpla->categories->delete_all_product_categories($product->id);
        foreach ($product_categories as $i => $category_id)
        {
            $simpla->categories->add_product_category($product->id, (int)$category_id, $i);
        }
    }

    $variant->compare_price = $parsed_product['price'];
    if (!empty($variant->id))
    {
        $simpla->variants->update_variant($variant->id, $variant);
    }
    else
    {
        $variant->product_id = $product->id;
        $variant->id         = $simpla->variants->add_variant($variant);
    }

    // Загрузка изображений из интернета и drag-n-drop файлов
    if (!empty($parsed_product['images']) && !$update_img)
    {
        foreach ($parsed_product['images'] as $i => $url)
        {
            $image_filename = pathinfo($url, PATHINFO_BASENAME);
            $simpla->db->query('SELECT filename FROM __images WHERE product_id=? AND (filename=? OR filename=?) LIMIT 1',
                $product->id, $image_filename, $url);
            //deb($image_filename);
            // Добавляем изображение только если такого еще нет в этом товаре
            if (!$simpla->db->result('filename'))
            {
                // Если не пустой адрес
                if (!empty($url) && $url != 'http://' && strstr($url, '/') !== false)
                {
                    $simpla->products->add_image($product->id, $url);
                }
            }
        }
    }
    // Свойства товара
    if (!empty($parsed_product['attributes']) && !empty($product_categories) && !$update_attr)
    {
        foreach ($parsed_product['attributes'] as $f_name => $value)
        {
            $simpla->db->query('SELECT id FROM __features WHERE `name` = ? LIMIT 1', $f_name);
            $feature_id = $simpla->db->result('id');
            if (!empty($feature_id))
            {
                // Категории свойства
                $feature_categories_ids = $simpla->features->get_feature_categories($feature_id);
                foreach ($product_categories as $p_cat)
                {
                    $search_cat_id = array_search($p_cat, $feature_categories_ids);
                    // Добавляем категорию, если она не связа с этим свойством
                    if (empty($search_cat_id))
                    {
                        // $okay->parser->logParser("add_feature_category feature_id: {$feature_id}; cat_id: {$p_cat}");
                        $simpla->features->add_feature_category($feature_id, $p_cat);
                    }
                }

                // Обновим/добавим значение свойства
                $simpla->features->update_option((int)$product->id, (int)$feature_id, $value);

                //$okay->parser->logParser("add_feature_value product_id: {$product_id}; feature_id: {$feature_id}; value: {$value}");
            }
            else
            {
                // Добавление свойства
                $feature_id = $simpla->features->add_feature(['name' => $f_name]);

                // Категория свойства
                foreach ($product_categories as $c_id)
                {
                    $simpla->features->add_feature_category($feature_id, $c_id);
                }
                // Обновление значений свойства
                $simpla->features->update_option($product->id, $feature_id, $value);
            }
        }
    }

    return $product->id;
}

function add_products_page($simpla, $parser, $start_position)
{
    $end_position = $start_position + 100;
    // изначальный список категорий
    if (!$simpla->db->query('SELECT * FROM __parsers_categories'))
    {
        $parseCategories = $parser->parseCategories();
        deb($parseCategories);
        foreach ($parseCategories as $parseCategory)
        {
            $simpla->parsers->add_parsers_category($parseCategory);
        }
    }
    // изначальный список спарсенных товаров
    $pageList = $parser->parsePageList();
    foreach ($pageList as $pl)
    {
        $product = $parser->getProduct($pl->site_url);
        if (!empty($product))
        {
            //обновление списка спарсеных товаров
            $simpla->db->query('SELECT id FROM __parsers_pages WHERE site_url=? LIMIT 1', $pl->site_url);
            if (!$simpla->db->result('id'))
            {
                $parsers_page           = new stdClass;
                $parsers_page->site_url = $pl->site_url;
                $parsers_page->id       = $simpla->parsers->add_parsers_pages($parsers_page);
            }
            $category = $product['breadcrumbs'][3];
            $simpla->db->query('SELECT id FROM __parsers_categories WHERE name=? AND url=? LIMIT 1', $category['name'],
                $category['url']);
            if (!$simpla->db->result('id') and !empty($category))
            {
                $parsers_category       = new stdClass;
                $parsers_category->url  = $category['url'];
                $parsers_category->name = $category['name'];
                $simpla->parsers->add_parsers_category($parsers_category);
            }
        }
    }

    return count($pageList);
}

function search_brand_in_name($name, $brands)
{
    if (is_array($brands))
    {
        foreach ($brands as $str)
        {
            if (is_array($str))
            {
                $pos = strpos_array($name, $str);
            }
            else
            {
                $pos = strpos(mb_strtolower($name), mb_strtolower($str));
            }
            if ($pos !== false)
            {
                return $str;
            }
        }
    }
    else
    {
        return strpos(mb_strtolower($name), mb_strtolower($brands));
    }
}