<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display the specified product.
     */
    public function show(string $id): JsonResponse
    {
        // Find the product by ID or fail automatically with a 404 error
        $product = Product::findOrFail($id);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
        ]);
    }
}