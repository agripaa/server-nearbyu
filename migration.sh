php artisan make:migration create_users_table --create=users
php artisan make:migration create_otp_code_table --create=otp_code
php artisan make:migration create_image_profile_table --create=image_profile
php artisan make:migration create_role_table --create=role
php artisan make:migration create_product_table --create=product
php artisan make:migration create_image_product_table --create=image_product
php artisan make:migration create_booking_table --create=booking
php artisan make:migration create_order_detail_table --create=order_detail
php artisan make:migration create_payment_method_table --create=payment_method
php artisan make:migration create_proof_of_payment_image_table --create=proof_of_payment_image
php artisan make:migration create_status_table --create=status
php artisan make:migration add_password_reset_fields_to_users_table --table=users