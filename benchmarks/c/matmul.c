#include <stdint.h>
#include <stddef.h>
#include <stdlib.h>

double *{{symbol}}(const double *a, size_t a_rows, size_t a_cols, const double *b, size_t b_rows, size_t b_cols,
                   int64_t *forja_rows, int64_t *forja_cols, int32_t *forja_err)
{
    *forja_rows = 0;
    *forja_cols = 0;
    if (a_rows == 0) {
        return NULL;
    }
    /* PHP reads $b[$k][$j] for k < a_cols and j < b_cols; anything else is an undefined-index warning. */
    if (a_cols == 0 || b_cols == 0 || b_rows < a_cols) {
        *forja_err = 1;
        return NULL;
    }
    double *out = malloc(a_rows * b_cols * sizeof(double));
    if (out == NULL) {
        *forja_err = 1;
        return NULL;
    }
    for (size_t i = 0; i < a_rows; i++) {
        for (size_t j = 0; j < b_cols; j++) {
            double sum = 0.0;
            for (size_t k = 0; k < a_cols; k++) {
                sum += a[i * a_cols + k] * b[k * b_cols + j];
            }
            out[i * b_cols + j] = sum;
        }
    }
    *forja_rows = (int64_t) a_rows;
    *forja_cols = (int64_t) b_cols;
    return out;
}
