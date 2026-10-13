#include <stdint.h>
#include <stddef.h>

double {{symbol}}(const double *a, size_t a_len, const double *b, size_t b_len, int32_t *accel_err)
{
    (void) accel_err;
    size_t n = a_len < b_len ? a_len : b_len;
    double sum = 0.0;
    for (size_t i = 0; i < n; i++) {
        sum += a[i] * b[i];
    }
    return sum;
}
