<?php

namespace App\Http\Controllers;

use App\Models\ImageProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with(['imageProduct', 'user'])->get();

        if ($products->isEmpty()) {
            return response()->json(['message' => 'No products found'], 200);
        }

        return response()->json($products, 200);
    }

    public function getindexWithoutLogin()
    {
        $products = Product::with(['imageProduct'])->get();

        if ($products->isEmpty()) {
            return response()->json(['message' => 'No products found'], 200);
        }
        
        return response()->json($products, 200);

    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'space_type' => 'required|string|max:255',
                'price' => 'required|integer',
                'kuota' => 'required|integer',
                'desc' => 'required|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            ]);
    
            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }

            $imagePath = $request->file('image')->store('images', 'public');
            $imageProduct = ImageProduct::create([
                'image_url' => Storage::url($imagePath),
            ]);
            $user = Auth::user();
    
            $product = Product::create([
                'space_type' => $request->space_type,
                'price' => $request->price,
                'kuota' => $request->kuota,
                'desc' => $request->desc,
                'image_product_id' => $imageProduct->id,
                'user_id' => $user->id,
            ]);
    
            if (!$product) {
                return response()->json(['message' => 'Product creation failed'], 500);
            }
            return response()->json($product, 201);
        } catch (\Exception $e) {
            Log::error('Error creating product: ' . $e->getMessage());
            return response()->json(['message' => 'Product creation failed', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $product = Product::with(['imageProduct', 'user'])->find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json($product, 200);
    }

    public function updateProduct(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'space_type' => 'required|string|max:255',
            'price' => 'required|integer',
            'kuota' => 'required|integer',
            'desc' => 'required|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            Log::error('Validation failed:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 400);
        }

        // Update product details
        $product->space_type = $request->space_type;
        $product->price = $request->price;
        $product->kuota = $request->kuota;
        $product->desc = $request->desc;

        // Update product image if exists
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('images', 'public');
            $imageProduct = ImageProduct::create([
                'image_url' => Storage::url($imagePath),
            ]);
            $product->image_product_id = $imageProduct->id;
        }

        $product->update();

        return response()->json($product, 200);
    }

    
    public function destroy($id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted'], 200);
    }
}
