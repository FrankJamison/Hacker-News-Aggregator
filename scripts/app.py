from flask import Flask, request, jsonify
import hn_fetch

app = Flask(__name__)


@app.get("/api/health")
def health():
    return jsonify(status="ok"), 200

# Example: wrap your existing function


@app.post("/api/run")
def run():
    data = request.get_json() or {}
    result = hn_fetch.main(data)  # adapt to your function
    return jsonify(result=result)


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=4020)
